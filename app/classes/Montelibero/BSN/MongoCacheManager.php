<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class MongoCacheManager
{
    private Manager $Mongo;
    private string $database;
    private string $collection = 'cache_entries';

    public function __construct(Manager $Mongo, string $database)
    {
        $this->Mongo = $Mongo;
        $this->database = $database;
    }

    public function fetch(string $key): ?array
    {
        $Query = new Query(
            ['key' => $key],
            ['limit' => 1]
        );
        $doc = current($this->Mongo->executeQuery($this->namespace(), $Query)->toArray()) ?: null;
        if (!$doc) {
            return null;
        }
        $doc = $this->normalizeMongoValue($doc);

        $expires_at = $doc['expires_at'] ?? null;
        if ($expires_at instanceof UTCDateTime && $expires_at->toDateTime()->getTimestamp() < time()) {
            $this->delete($key);
            return null;
        }

        $this->incrementReadCount($key);
        $doc['read_count'] = ((int) ($doc['read_count'] ?? 0)) + 1;

        return $this->normalizeEntry($doc);
    }

    public function store(string $key, mixed $data, ?int $ttl = null, array $meta = []): array
    {
        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $expires_at = $ttl !== null ? new UTCDateTime((time() + $ttl) * 1000) : null;

        $Bulk = new BulkWrite();
        $Bulk->update(
            ['key' => $key],
            [
                '$set' => [
                    'key' => $key,
                    'updated_at' => $Now,
                    'expires_at' => $expires_at,
                    'meta' => $meta,
                    'data' => $data,
                ],
                '$setOnInsert' => [
                    'created_at' => $Now,
                    'read_count' => 0,
                ],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);

        return [
            'key' => $key,
            'created_at' => $Now->toDateTime()->format('Y-m-d H:i:s'),
            'created_at_ts' => $Now->toDateTime()->getTimestamp(),
            'updated_at' => $Now->toDateTime()->format('Y-m-d H:i:s'),
            'updated_at_ts' => $Now->toDateTime()->getTimestamp(),
            'expires_at' => $expires_at?->toDateTime()->format('Y-m-d H:i:s'),
            'expires_at_ts' => $expires_at?->toDateTime()->getTimestamp(),
            'read_count' => 0,
            'meta' => $meta,
            'data' => $data,
        ];
    }

    public function delete(string $key): void
    {
        $Bulk = new BulkWrite();
        $Bulk->delete(['key' => $key], ['limit' => 1]);
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
    }

    private function incrementReadCount(string $key): void
    {
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['key' => $key],
            ['$inc' => ['read_count' => 1]],
            ['limit' => 1]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
    }

    private function normalizeEntry(array $doc): array
    {
        $created_at = $doc['created_at'] ?? null;
        $updated_at = $doc['updated_at'] ?? null;
        $expires_at = $doc['expires_at'] ?? null;

        return [
            'key' => $doc['key'] ?? null,
            'created_at' => $created_at instanceof UTCDateTime ? $created_at->toDateTime()->format('Y-m-d H:i:s') : null,
            'created_at_ts' => $created_at instanceof UTCDateTime ? $created_at->toDateTime()->getTimestamp() : null,
            'updated_at' => $updated_at instanceof UTCDateTime ? $updated_at->toDateTime()->format('Y-m-d H:i:s') : null,
            'updated_at_ts' => $updated_at instanceof UTCDateTime ? $updated_at->toDateTime()->getTimestamp() : null,
            'expires_at' => $expires_at instanceof UTCDateTime ? $expires_at->toDateTime()->format('Y-m-d H:i:s') : null,
            'expires_at_ts' => $expires_at instanceof UTCDateTime ? $expires_at->toDateTime()->getTimestamp() : null,
            'read_count' => (int) ($doc['read_count'] ?? 0),
            'meta' => (array) ($doc['meta'] ?? []),
            'data' => $doc['data'] ?? null,
        ];
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->collection);
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
