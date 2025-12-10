<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\Exception;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\WriteConcern;
use SessionHandlerInterface;

class MongoSessionHandler implements SessionHandlerInterface
{
    private Manager $manager;
    private string $database;
    private string $collection;
    private int $ttlSeconds;
    private WriteConcern $writeConcern;

    public function __construct(Manager $manager, string $database, string $collection, int $ttlSeconds)
    {
        $this->manager = $manager;
        $this->database = $database;
        $this->collection = $collection;
        $this->ttlSeconds = $ttlSeconds;
        // Одноузловый инстанс: w=1 надёжнее, чем MAJORITY (который требует реплику)
        $this->writeConcern = new WriteConcern(1, 1000);

        $this->ensureIndexes();
    }

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        try {
            $filter = [
                '_id' => $id,
                'expiresAt' => [
                    '$gt' => new UTCDateTime(time() * 1000),
                ],
            ];
            $query = new Query($filter, ['limit' => 1]);
            $cursor = $this->manager->executeQuery($this->namespace(), $query);
            $document = current($cursor->toArray());

            if (!$document) {
                return '';
            }

            return (string) ($document->data ?? '');
        } catch (Exception $e) {
            $this->logException('read', $e);
            return '';
        }
    }

    public function write($id, $data): bool
    {
        try {
            $expiresAt = new UTCDateTime((time() + $this->ttlSeconds) * 1000);
            $bulk = new BulkWrite();
            $bulk->update(
                ['_id' => $id],
                [
                    '$set' => [
                        'data' => $data,
                        'expiresAt' => $expiresAt,
                    ],
                ],
                ['upsert' => true]
            );

            $this->manager->executeBulkWrite(
                $this->namespace(),
                $bulk,
                ['writeConcern' => $this->writeConcern]
            );
            return true;
        } catch (Exception $e) {
            $this->logException('write', $e);
            return false;
        }
    }

    public function destroy($id): bool
    {
        try {
            $bulk = new BulkWrite();
            $bulk->delete(['_id' => $id], ['limit' => 1]);
            $this->manager->executeBulkWrite(
                $this->namespace(),
                $bulk,
                ['writeConcern' => $this->writeConcern]
            );
            return true;
        } catch (Exception $e) {
            $this->logException('destroy', $e);
            return false;
        }
    }

    public function gc($max_lifetime): false|int
    {
        try {
            $bulk = new BulkWrite();
            $bulk->delete(
                [
                    'expiresAt' => [
                        '$lte' => new UTCDateTime(time() * 1000),
                    ],
                ]
            );
            $result = $this->manager->executeBulkWrite(
                $this->namespace(),
                $bulk,
                ['writeConcern' => $this->writeConcern]
            );
            return $result->getDeletedCount();
        } catch (Exception $e) {
            $this->logException('gc', $e);
            return false;
        }
    }

    private function ensureIndexes(): void
    {
        try {
            $command = new Command([
                'createIndexes' => $this->collection,
                'indexes' => [
                    [
                        'key' => ['expiresAt' => 1],
                        'name' => 'expires_ttl',
                        'expireAfterSeconds' => 0,
                    ],
                ],
            ]);
            $this->manager->executeCommand($this->database, $command);
        } catch (Exception $e) {
            // Best-effort: если не получилось создать индекс, сессии все равно работают, но cleanup ляжет на gc()
            $this->logException('ensureIndexes', $e);
        }
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->collection);
    }

    private function logException(string $stage, Exception $exception): void
    {
        error_log(
            sprintf(
                '[MongoSessionHandler:%s] %s (%s)',
                $stage,
                $exception->getMessage(),
                get_class($exception)
            )
        );
    }
}
