<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class ContactsManager
{
    private Manager $Mongo;
    private string $database;
    private string $collection = 'contacts';

    private static bool $indexes_ensured = false;

    public function __construct(Manager $Mongo, string $database)
    {
        $this->Mongo = $Mongo;
        $this->database = $database;

        if (!self::$indexes_ensured) {
            $this->ensureIndexes();
            self::$indexes_ensured = true;
        }
    }

    public function getContacts(string $host_account_id, ?string $stellar_address = null): array
    {
        $filter = ['account_id' => $host_account_id];
        $query = new Query($filter, ['limit' => 1]);
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        $doc = current($cursor->toArray());
        $contacts = (array) ($doc->contacts ?? []);

        if ($stellar_address) {
            if (!isset($contacts[$stellar_address])) {
                return [];
            }
            return [$stellar_address => $this->normalizeContact((array) $contacts[$stellar_address])];
        }

        foreach ($contacts as $address => $value) {
            $contacts[$address] = $this->normalizeContact((array) $value);
        }

        return $contacts;
    }

    private function normalizeContact(array $value): array
    {
        $updated = $value['updated_at'] ?? null;
        if ($updated instanceof UTCDateTime) {
            $updated = $updated->toDateTime()->format('Y-m-d H:i:s');
        }

        return [
            'name' => $value['name'] ?? null,
            'time' => $updated,
        ];
    }

    public function getContact(string $host_account_id, $id): ?array
    {
        $contacts = $this->getContacts($host_account_id, $id);

        return $contacts ? $contacts[$id] : null;
    }

    public function addContact(string $host_account_id, string $stellar_account, ?string $name = null): void
    {
        $this->upsertContact($host_account_id, $stellar_account, $name);
    }

    public function updateContact(string $host_account_id, string $stellar_account, ?string $name): void
    {
        $this->upsertContact($host_account_id, $stellar_account, $name);
    }

    public function deleteContact(string $host_account_id, $stellar_account): void
    {
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['account_id' => $host_account_id],
            [
                '$unset' => ["contacts.$stellar_account" => ""],
                '$set' => ['updated_at' => new UTCDateTime(time() * 1000)],
            ]
        );
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk
        );
    }

    private function upsertContact(string $host_account_id, string $stellar_account, ?string $name): void
    {
        $Now = new UTCDateTime(time() * 1000);
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['account_id' => $host_account_id],
            [
                '$set' => [
                    "contacts.$stellar_account" => [
                        'name' => $name,
                        'updated_at' => $Now,
                    ],
                    'updated_at' => $Now,
                ],
                '$setOnInsert' => ['account_id' => $host_account_id],
            ],
            ['upsert' => true]
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
                        ['key' => ['account_id' => 1], 'name' => 'uniq_account', 'unique' => true],
                    ],
                ])
            );
        } catch (\Throwable $e) {
            error_log('[contacts_indexes] ' . $e->getMessage());
        }
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->collection);
    }
}
