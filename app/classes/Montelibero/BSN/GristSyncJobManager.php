<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class GristSyncJobManager
{
    private const COLLECTION = 'grist_sync_jobs';
    private const LEASE_SECONDS = 300;
    private const RETRY_SECONDS = 300;

    public function __construct(
        private readonly Manager $Mongo,
        private readonly string $database,
    ) {
    }

    public function schedule(string $scope, int $delay_seconds = 60): void
    {
        GristSyncService::assertScope($scope);

        $now_ms = (int) (microtime(true) * 1000);
        $Now = new UTCDateTime($now_ms);
        $DueAt = new UTCDateTime($now_ms + max(0, $delay_seconds) * 1000);
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $scope],
            [
                '$set' => [
                    'scope' => $scope,
                    'requested_at' => $Now,
                    'due_at' => $DueAt,
                    'last_error' => null,
                ],
                '$inc' => ['revision' => 1],
                '$setOnInsert' => [
                    'completed_revision' => 0,
                    'created_at' => $Now,
                ],
                '$unset' => ['retry_after' => ''],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
    }

    /**
     * @return array{scope: string, revision: int}|null
     */
    public function claimNextDue(): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $Now = new UTCDateTime((int) (microtime(true) * 1000));
            $filter = $this->dueFilter($Now);
            $Query = new Query($filter, ['sort' => ['due_at' => 1], 'limit' => 1]);
            $document = current($this->Mongo->executeQuery($this->namespace(), $Query)->toArray()) ?: null;
            if ($document === null) {
                return null;
            }

            $scope = (string) ($document->scope ?? $document->_id ?? '');
            $revision = (int) ($document->revision ?? 0);
            if ($scope === '' || $revision < 1) {
                throw new \RuntimeException('Invalid Grist sync job document.');
            }

            $LeaseUntil = new UTCDateTime((int) ((microtime(true) + self::LEASE_SECONDS) * 1000));
            $Bulk = new BulkWrite();
            $Bulk->update(
                $this->claimFilter($scope, $revision, $Now),
                [
                    '$set' => [
                        'lease_until' => $LeaseUntil,
                        'running_revision' => $revision,
                        'started_at' => $Now,
                    ],
                ],
                ['limit' => 1]
            );
            $result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
            if ($result->getModifiedCount() === 1) {
                return ['scope' => $scope, 'revision' => $revision];
            }
        }

        return null;
    }

    public function complete(string $scope, int $revision, array $result_data): void
    {
        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $scope, 'running_revision' => $revision],
            [
                '$set' => [
                    'completed_revision' => $revision,
                    'last_success_at' => $Now,
                    'last_result' => $result_data,
                    'last_error' => null,
                ],
                '$unset' => [
                    'lease_until' => '',
                    'running_revision' => '',
                    'retry_after' => '',
                ],
            ],
            ['limit' => 1]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
    }

    public function fail(string $scope, int $revision, \Throwable $Exception): void
    {
        $now_ms = (int) (microtime(true) * 1000);
        $Now = new UTCDateTime($now_ms);
        $RetryAfter = new UTCDateTime($now_ms + self::RETRY_SECONDS * 1000);

        $Bulk = new BulkWrite();
        $Bulk->update(
            [
                '_id' => $scope,
                'running_revision' => $revision,
                'revision' => $revision,
            ],
            [
                '$set' => [
                    'last_error' => $Exception->getMessage(),
                    'last_failure_at' => $Now,
                    'retry_after' => $RetryAfter,
                ],
                '$unset' => ['lease_until' => '', 'running_revision' => ''],
            ],
            ['limit' => 1]
        );
        $result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
        if ($result->getModifiedCount() === 1) {
            return;
        }

        // A newer webhook arrived while this revision was running. Keep its due_at
        // untouched and release the lease so the newer revision can run normally.
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $scope, 'running_revision' => $revision],
            [
                '$set' => [
                    'last_error' => $Exception->getMessage(),
                    'last_failure_at' => $Now,
                ],
                '$unset' => ['lease_until' => '', 'running_revision' => ''],
            ],
            ['limit' => 1]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
    }

    private function dueFilter(UTCDateTime $Now): array
    {
        return [
            'due_at' => ['$lte' => $Now],
            '$expr' => [
                '$gt' => [
                    '$revision',
                    ['$ifNull' => ['$completed_revision', 0]],
                ],
            ],
            '$and' => [
                ['$or' => [
                    ['lease_until' => ['$exists' => false]],
                    ['lease_until' => null],
                    ['lease_until' => ['$lte' => $Now]],
                ]],
                ['$or' => [
                    ['retry_after' => ['$exists' => false]],
                    ['retry_after' => null],
                    ['retry_after' => ['$lte' => $Now]],
                ]],
            ],
        ];
    }

    private function claimFilter(string $scope, int $revision, UTCDateTime $Now): array
    {
        $filter = $this->dueFilter($Now);
        $filter['_id'] = $scope;
        $filter['revision'] = $revision;

        return $filter;
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, self::COLLECTION);
    }
}
