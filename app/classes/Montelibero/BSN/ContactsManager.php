<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class ContactsManager
{
    private Manager $Mongo;
    private string $database;
    private string $collection = 'contacts';

    public function __construct(Manager $Mongo, string $database)
    {
        $this->Mongo = $Mongo;
        $this->database = $database;
    }

    public function getContacts(string $host_account_id, ?string $stellar_address = null): array
    {
        $filter = ['account_id' => $host_account_id];
        $options = ['limit' => 1];

        if ($stellar_address !== null) {
            $filter["$this->collection.$stellar_address"] = ['$exists' => true];
            $options['projection'] = [
                "$this->collection.$stellar_address" => 1,
                '_id' => 0,
            ];
        }

        $query = new Query($filter, $options);
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        $doc = current($cursor->toArray()) ?: null;
        $contacts = (array) (($doc?->contacts) ?? []);

        if ($stellar_address !== null) {
            if (!isset($contacts[$stellar_address])) {
                return [];
            }
            $contact = (array) $contacts[$stellar_address];
            if (($contact['name'] ?? null) === null) {
                return [];
            }
            return [$stellar_address => $this->normalizeContact($contact)];
        }

        foreach ($contacts as $address => $value) {
            $value = (array) $value;
            if (($value['name'] ?? null) === null) {
                unset($contacts[$address]);
                continue;
            }
            $contacts[$address] = $this->normalizeContact($value);
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
        $this->upsertContact($host_account_id, $stellar_account, $name ?? '');
    }

    public function updateContact(string $host_account_id, string $stellar_account, ?string $name): void
    {
        $this->upsertContact($host_account_id, $stellar_account, $name ?? '');
    }

    public function deleteContact(string $host_account_id, $stellar_account): void
    {
        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['account_id' => $host_account_id],
            [[
                '$set' => [
                    'revision' => $this->nextRevisionExpression(),
                    "$this->collection.$stellar_account.name" => ['$literal' => null],
                    "$this->collection.$stellar_account.updated_at" => ['$literal' => $Now],
                    "$this->collection.$stellar_account.revision" => $this->nextRevisionExpression(),
                    'updated_at' => ['$literal' => $Now],
                ],
            ]]
        );
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk
        );
    }

    private function upsertContact(string $host_account_id, string $stellar_account, ?string $name): void
    {
        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['account_id' => $host_account_id],
            [[
                '$set' => [
                    'account_id' => ['$ifNull' => ['$account_id', ['$literal' => $host_account_id]]],
                    'revision' => $this->nextRevisionExpression(),
                    "$this->collection.$stellar_account.name" => ['$literal' => $name],
                    "$this->collection.$stellar_account.updated_at" => ['$literal' => $Now],
                    "$this->collection.$stellar_account.revision" => $this->nextRevisionExpression(),
                    'updated_at' => ['$literal' => $Now],
                ],
            ]],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk
        );
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->collection);
    }

    public function getAllItems(string $host_account_id): array
    {
        return $this->getSyncSnapshot($host_account_id)['items'];
    }

    /**
     * @return array{revision: int, items: array<string, array{label: ?string, updated_at: int, revision: int}>}
     */
    public function getSyncSnapshot(string $host_account_id): array
    {
        $filter = ['account_id' => $host_account_id];
        $options = ['limit' => 1];
        $query = new Query($filter, $options);
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        $doc = current($cursor->toArray()) ?: null;
        $contacts = (array) (($doc?->contacts) ?? []);

        foreach ($contacts as $address => $value) {
            $value = (array) $value;
            /** @var UTCDateTime $updated_at */
            $updated_at = $value['updated_at'];
            $contacts[$address] = [
                'label' => $value['name'],
                'updated_at' => (int)(string) $updated_at,
                'revision' => (int) ($value['revision'] ?? 0),
            ];
        }

        return [
            'revision' => (int) ($doc?->revision ?? 0),
            'items' => $contacts,
        ];
    }

    public function bulkUpdate(string $account_id, array $bulk_update, int $expected_revision): bool
    {
        if (empty($bulk_update)) {
            return true;
        }

        $Now = new UTCDateTime((int) (microtime(true) * 1000));
        $bulk_update_prep = [
            'account_id' => ['$ifNull' => ['$account_id', ['$literal' => $account_id]]],
            'revision' => $this->nextRevisionExpression(),
            'updated_at' => ['$literal' => $Now],
        ];
        foreach ($bulk_update as $key => $value) {
            $bulk_update_prep["$this->collection.$key.name"] = ['$literal' => $value['name']];
            $bulk_update_prep["$this->collection.$key.updated_at"] = ['$literal' => $value['updated_at']];
            $bulk_update_prep["$this->collection.$key.revision"] = $this->nextRevisionExpression();
        }

        $filter = ['account_id' => $account_id];
        if ($expected_revision === 0) {
            $filter['$or'] = [
                ['revision' => 0],
                ['revision' => ['$exists' => false]],
            ];
        } else {
            $filter['revision'] = $expected_revision;
        }

        $Bulk = new BulkWrite();
        $Bulk->update(
            $filter,
            [['$set' => $bulk_update_prep]],
            ['upsert' => $expected_revision === 0]
        );
        try {
            $Result = $this->Mongo->executeBulkWrite(
                $this->namespace(),
                $Bulk
            );
        } catch (BulkWriteException $Exception) {
            if ($Exception->getCode() === 11000) {
                return false;
            }

            throw $Exception;
        }

        return $Result->getMatchedCount() > 0 || $Result->getUpsertedCount() > 0;
    }

    private function nextRevisionExpression(): array
    {
        return ['$add' => [['$ifNull' => ['$revision', 0]], 1]];
    }
}
