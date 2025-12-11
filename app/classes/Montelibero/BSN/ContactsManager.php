<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\WriteConcern;

class ContactsManager
{
    private string $account_id;
    private Manager $Mongo;
    private string $database;
    private string $collection = 'contacts';
    private WriteConcern $writeConcern;

    private static ?Manager $SharedManager = null;
    private static bool $indexesEnsured = false;

    private $contacts = [];

    public function __construct($account_id, ?Manager $Mongo = null, ?string $database = null)
    {
        $this->account_id = $account_id;

        if ($Mongo) {
            self::$SharedManager = $Mongo;
        } elseif (!self::$SharedManager) {
            $authSource = $_ENV['MONGO_AUTH_SOURCE'] ?? 'admin';
            $mongoUri = sprintf(
                'mongodb://%s:%s@%s:%s/?authSource=%s',
                $_ENV['MONGO_ROOT_USERNAME'],
                $_ENV['MONGO_ROOT_PASSWORD'],
                $_ENV['MONGO_HOST'],
                $_ENV['MONGO_PORT'],
                $authSource
            );
            self::$SharedManager = new Manager($mongoUri);
        }

        $this->Mongo = self::$SharedManager;
        $this->database = $database ?? ($_ENV['MONGO_BASENAME'] ?? 'app_db');
        $this->writeConcern = new WriteConcern(1, 1000);

        if (!self::$indexesEnsured) {
            $this->ensureIndexes();
            self::$indexesEnsured = true;
        }
    }

    public function getContacts(?string $stellar_address = null): array
    {
        $filter = ['account_id' => $this->account_id];
        $query = new Query($filter, ['limit' => 1]);
        $cursor = $this->Mongo->executeQuery($this->namespace(), $query);
        $doc = current($cursor->toArray());
        $contacts = (array) ($doc->contacts ?? []);

        $normalize = function ($value) {
            $updated = $value['updated_at'] ?? null;
            if ($updated instanceof UTCDateTime) {
                $updated = $updated->toDateTime()->format('Y-m-d H:i:s');
            }
            return [
                'name' => $value['name'] ?? null,
                'time' => $updated,
            ];
        };

        if ($stellar_address) {
            if (!isset($contacts[$stellar_address])) {
                return [];
            }
            return [$stellar_address => $normalize((array) $contacts[$stellar_address])];
        }

        foreach ($contacts as $address => $value) {
            $this->contacts[$address] = $normalize((array) $value);
        }

        return $this->contacts;
    }

    public function getContact($id): ?array
    {
        $contacts = $this->getContacts($id);

        return $contacts ? $contacts[$id] : null;
    }

    public function addContact(string $stellar_account, ?string $name = null): void
    {
        $this->upsertContact($stellar_account, $name);
    }

    public function updateContact(string $stellar_account, ?string $name)
    {
        $this->upsertContact($stellar_account, $name);
    }

    public function deleteContact($stellar_account)
    {
        $bulk = new BulkWrite();
        $bulk->update(
            ['account_id' => $this->account_id],
            [
                '$unset' => ["contacts.$stellar_account" => ""],
                '$set' => ['updated_at' => new UTCDateTime(time() * 1000)],
            ]
        );
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $bulk,
            ['writeConcern' => $this->writeConcern]
        );
    }

    private function upsertContact(string $stellar_account, ?string $name): void
    {
        $now = new UTCDateTime(time() * 1000);
        $bulk = new BulkWrite();
        $bulk->update(
            ['account_id' => $this->account_id],
            [
                '$set' => [
                    "contacts.$stellar_account" => [
                        'name' => $name,
                        'updated_at' => $now,
                    ],
                    'updated_at' => $now,
                ],
                '$setOnInsert' => ['account_id' => $this->account_id],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $bulk,
            ['writeConcern' => $this->writeConcern]
        );
    }

    private function ensureIndexes(): void
    {
        try {
            $this->Mongo->executeCommand(
                $this->database,
                new \MongoDB\Driver\Command([
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
