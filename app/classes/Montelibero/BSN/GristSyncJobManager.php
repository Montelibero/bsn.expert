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
                    'last_trigger' => 'worker',
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

    public function recordManualSuccess(string $scope, array $result_data, int $satisfied_revision): void
    {
        GristSyncService::assertScope($scope);
        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $scope],
            [
                '$set' => [
                    'scope' => $scope,
                    'last_success_at' => $Now,
                    'last_result' => $result_data,
                    'last_error' => null,
                    'last_trigger' => 'admin',
                ],
                '$setOnInsert' => [
                    'revision' => 0,
                    'created_at' => $Now,
                ],
                '$max' => ['completed_revision' => max(0, $satisfied_revision)],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
    }

    public function recordManualFailure(string $scope, \Throwable $Exception): void
    {
        GristSyncService::assertScope($scope);
        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $scope],
            [
                '$set' => [
                    'scope' => $scope,
                    'last_failure_at' => $Now,
                    'last_error' => $Exception->getMessage(),
                    'last_trigger' => 'admin',
                ],
                '$setOnInsert' => [
                    'revision' => 0,
                    'completed_revision' => 0,
                    'created_at' => $Now,
                ],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
    }

    /**
     * @return array{
     *     state: string,
     *     revision: int,
     *     completed_revision: int,
     *     running_revision: ?int,
     *     due_at_ts: ?int,
     *     retry_after_ts: ?int,
     *     last_success_at_ts: ?int,
     *     last_failure_at_ts: ?int,
     *     last_result: array,
     *     last_error: ?string,
     *     last_trigger: ?string
     * }
     */
    public function status(string $scope): array
    {
        GristSyncService::assertScope($scope);
        $Query = new Query(['_id' => $scope], ['limit' => 1]);
        $document = current($this->Mongo->executeQuery($this->namespace(), $Query)->toArray()) ?: null;
        $revision = (int) ($document->revision ?? 0);
        $completed_revision = (int) ($document->completed_revision ?? 0);
        $running_revision = isset($document->running_revision) ? (int) $document->running_revision : null;
        $now = time();
        $lease_until = $this->timestamp($document->lease_until ?? null);
        $due_at = $this->timestamp($document->due_at ?? null);
        $retry_after = $this->timestamp($document->retry_after ?? null);

        $state = 'idle';
        if ($running_revision !== null && $lease_until !== null && $lease_until > $now) {
            $state = 'running';
        } elseif ($revision > $completed_revision) {
            if ($retry_after !== null && $retry_after > $now) {
                $state = 'retry';
            } elseif ($due_at !== null && $due_at > $now) {
                $state = 'scheduled';
            } else {
                $state = 'pending';
            }
        }

        $last_result = $this->normalizeMongoValue($document->last_result ?? []);

        return [
            'state' => $state,
            'revision' => $revision,
            'completed_revision' => $completed_revision,
            'running_revision' => $running_revision,
            'due_at_ts' => $due_at,
            'retry_after_ts' => $retry_after,
            'last_success_at_ts' => $this->timestamp($document->last_success_at ?? null),
            'last_failure_at_ts' => $this->timestamp($document->last_failure_at ?? null),
            'last_result' => is_array($last_result) ? $last_result : [],
            'last_error' => isset($document->last_error) ? (string) $document->last_error : null,
            'last_trigger' => isset($document->last_trigger) ? (string) $document->last_trigger : null,
        ];
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

    private function timestamp(mixed $value): ?int
    {
        return $value instanceof UTCDateTime
            ? $value->toDateTime()->getTimestamp()
            : null;
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeMongoValue($item);
        }

        return $value;
    }
}
