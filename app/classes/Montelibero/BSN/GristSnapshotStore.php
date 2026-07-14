<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class GristSnapshotStore
{
    private const COLLECTION = 'grist_snapshots';

    public function __construct(
        private readonly Manager $Mongo,
        private readonly string $database,
    ) {
    }

    /**
     * @return array{scope: string, version: int, data: array, updated_at_ts: ?int}|null
     */
    public function fetch(string $scope): ?array
    {
        $Query = new Query(['_id' => $scope], ['limit' => 1]);
        $document = current($this->Mongo->executeQuery($this->namespace(), $Query)->toArray()) ?: null;
        if ($document === null) {
            return null;
        }

        $document = $this->normalizeMongoValue($document);
        $updated_at = $document['updated_at'] ?? null;

        return [
            'scope' => (string) ($document['_id'] ?? $scope),
            'version' => (int) ($document['version'] ?? 0),
            'data' => is_array($document['data'] ?? null) ? $document['data'] : [],
            'updated_at_ts' => $updated_at instanceof UTCDateTime
                ? $updated_at->toDateTime()->getTimestamp()
                : null,
        ];
    }

    /**
     * @return array{scope: string, version: int, data: array, updated_at_ts: ?int}
     */
    public function store(string $scope, array $data): array
    {
        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $scope],
            [
                '$set' => [
                    'data' => $data,
                    'updated_at' => $Now,
                ],
                '$inc' => ['version' => 1],
                '$setOnInsert' => ['created_at' => $Now],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);

        return $this->fetch($scope) ?? throw new \RuntimeException(
            sprintf('Unable to read the stored Grist snapshot for scope "%s".', $scope)
        );
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, self::COLLECTION);
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return $value;
        }

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
