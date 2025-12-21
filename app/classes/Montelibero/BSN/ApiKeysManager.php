<?php

namespace Montelibero\BSN;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class ApiKeysManager
{
    private Manager $Mongo;
    private string $database;
    private string $collection = 'api_keys';

    private static bool $indexes_ensured = false;

    public function __construct(Manager $Mongo, ?string $database = null)
    {
        $this->Mongo = $Mongo;
        $this->database = $database;

        if (!self::$indexes_ensured) {
            $this->ensureIndexes();
            self::$indexes_ensured = true;
        }
    }

    public function createKey(string $account_id, string $name, array $permissions): array
    {
        $Now = new UTCDateTime(time() * 1000);
        $key = $this->generateUniqueKey();
        $normalized_permissions = $this->normalizePermissions($permissions);

        $Bulk = new BulkWrite();
        $insertData = [
            'account_id' => $account_id,
            'name' => $name,
            'key' => $key,
            'permissions' => $normalized_permissions,
            'created_at' => $Now,
        ];
        $insertedId = $Bulk->insert($insertData);
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk
        );

        $insertedDoc = (object) $insertData;
        $insertedDoc->_id = $insertedId;

        return $this->formatKeyDoc($insertedDoc);
    }

    public function getKeysByAccount(string $account_id): array
    {
        $Query = new Query(
            ['account_id' => $account_id],
            ['sort' => ['created_at' => -1]]
        );
        $Cursor = $this->Mongo->executeQuery($this->namespace(), $Query);
        $docs = $Cursor->toArray();

        $keys = array_map(function ($doc) {
            return $this->formatKeyDoc($doc);
        }, $docs);

        usort($keys, function ($a, $b) {
            $a_unused = $a['last_used_at'] === null;
            $b_unused = $b['last_used_at'] === null;

            if ($a_unused && !$b_unused) {
                return -1;
            }
            if (!$a_unused && $b_unused) {
                return 1;
            }

            if ($a_unused && $b_unused) {
                return ($b['created_at_ts'] ?? 0) <=> ($a['created_at_ts'] ?? 0);
            }

            return ($b['last_used_at_ts'] ?? 0) <=> ($a['last_used_at_ts'] ?? 0);
        });

        return $keys;
    }

    public function deleteKey(string $account_id, string $id): bool
    {
        try {
            $objectId = new ObjectId($id);
        } catch (\Throwable) {
            return false;
        }

        $Bulk = new BulkWrite();
        $Bulk->delete(
            ['_id' => $objectId, 'account_id' => $account_id],
            ['limit' => 1]
        );
        $result = $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk
        );

        return (bool) $result->getDeletedCount();
    }

    public function findByKey(string $key): ?array
    {
        $Query = new Query(['key' => $key], ['limit' => 1]);
        $Cursor = $this->Mongo->executeQuery($this->namespace(), $Query);
        $Doc = current($Cursor->toArray());

        return $Doc ? $this->formatKeyDoc($Doc) : null;
    }

    public function markUsed(string $key, string $ip): void
    {
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['key' => $key],
            [
                '$set' => [
                    'last_used_at' => new UTCDateTime(time() * 1000),
                    'last_ip' => $ip,
                ],
            ],
            ['limit' => 1]
        );
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk
        );
    }

    private function ensureIndexes(): void
    {
        try {
            $this->Mongo->executeCommand(
                $this->database,
                new Command([
                    'createIndexes' => $this->collection,
                    'indexes' => [
                        ['key' => ['key' => 1], 'name' => 'uniq_key', 'unique' => true],
                        ['key' => ['account_id' => 1], 'name' => 'account_idx'],
                    ],
                ])
            );
        } catch (\Throwable $e) {
            error_log('[api_keys_indexes] ' . $e->getMessage());
        }
    }

    private function generateUniqueKey(): string
    {
        $attempts = 0;
        do {
            $key = bin2hex(random_bytes(24));
            $exists = $this->findByKeyRaw($key);
            $attempts++;
        } while ($exists && $attempts < 5);

        if ($exists) {
            throw new \RuntimeException('Unable to generate unique API key');
        }

        return $key;
    }

    private function findByKeyRaw(string $key): ?object
    {
        $Query = new Query(['key' => $key], ['limit' => 1]);
        $Cursor = $this->Mongo->executeQuery($this->namespace(), $Query);
        return current($Cursor->toArray()) ?: null;
    }

    private function formatKeyDoc(object $doc): array
    {
        $created_at = $doc->created_at ?? null;
        $last_used_at = $doc->last_used_at ?? null;
        $created_dt = $created_at instanceof UTCDateTime ? $created_at->toDateTime() : null;
        $last_used_dt = $last_used_at instanceof UTCDateTime ? $last_used_at->toDateTime() : null;

        $permissions = (array) ($doc->permissions ?? []);
        $permissions['contacts'] = array_map(
            'boolval',
            (array) ($permissions['contacts'] ?? [])
        );

        return [
            'id' => (string) $doc->_id,
            'account_id' => $doc->account_id ?? null,
            'name' => $doc->name ?? '',
            'key' => $doc->key ?? null,
            'permissions' => $permissions,
            'created_at' => $created_dt ? $created_dt->format('Y-m-d H:i:s') : null,
            'created_at_ts' => $created_dt?->getTimestamp(),
            'last_used_at' => $last_used_dt ? $last_used_dt->format('Y-m-d H:i:s') : null,
            'last_used_at_ts' => $last_used_dt?->getTimestamp(),
            'last_ip' => $doc->last_ip ?? null,
        ];
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->collection);
    }

    private function normalizePermissions(array $permissions): array
    {
        $defaults = [
            'contacts' => [
                'read' => false,
                'create' => false,
                'update' => false,
                'delete' => false,
            ],
        ];

        foreach ($defaults as $scope => $actions) {
            foreach ($actions as $action => $_) {
                $defaults[$scope][$action] = (bool) ($permissions[$scope][$action] ?? false);
            }
        }

        return $defaults;
    }
}
