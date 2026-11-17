<?php

declare(strict_types=1);

namespace Montelibero\BSN\MTLA;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use RuntimeException;

class TgMembersCache
{
    public const MAX_AGE = 86400;
    private const COLLECTION = 'cache_entries';
    private readonly ?string $key;

    public function __construct(
        private readonly Manager $Mongo,
        private readonly string $database,
        #[\SensitiveParameter] string $key,
    ) {
        // A replacement key may grant access to a different set of chats.
        $key = trim($key);
        $this->key = $key === '' ? null : 'tg-members:' . hash('sha256', $key);
    }

    /** @return array<int|string, array{chats: list<string>, username: ?string}>|null */
    public function users(): ?array
    {
        if ($this->key === null) {
            return null;
        }
        $snapshot = $this->snapshot();
        if (($snapshot['checked_at'] ?? 0) <= time() - self::MAX_AGE) {
            return null;
        }

        $users = [];
        $last_seen = [];
        foreach ($snapshot['chats'] as $id => $chat) {
            foreach ($chat['members'] as $user_id => $username) {
                $users[$user_id]['chats'][] = (string) $id;
                // TG Members joins global user profiles at read time. The chat's own
                // collection timestamp says nothing about when we saw this username.
                if ($chat['fetched_at'] > ($last_seen[$user_id] ?? 0)) {
                    $users[$user_id]['username'] = $username;
                    $last_seen[$user_id] = $chat['fetched_at'];
                }
            }
        }
        return $users;
    }

    protected function snapshot(): array
    {
        $cursor = $this->Mongo->executeQuery(
            $this->database . '.' . self::COLLECTION,
            new Query(['key' => $this->key], ['limit' => 1, 'projection' => ['chats' => 1, 'checked_at' => 1]])
        );
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        return $cursor->toArray()[0] ?? [];
    }

    /** Claim the current snapshot. Only this run can publish until the next claim or revocation. */
    public function begin(string $run): ?array
    {
        if ($this->key === null) {
            return null;
        }
        $cursor = $this->Mongo->executeCommand($this->database, new Command([
            'findAndModify' => self::COLLECTION,
            'query' => ['key' => $this->key],
            'update' => [
                '$set' => ['run' => $run, 'expires_at' => new UTCDateTime((time() + self::MAX_AGE) * 1000)],
                '$setOnInsert' => ['checked_at' => 0, 'chats' => []],
            ],
            'upsert' => true,
            'new' => true,
        ]));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        return $cursor->toArray()[0]['value'];
    }

    public function publish(string $run, array $chats, int $checked_at): void
    {
        // ponytail: All visible chats fit in one Mongo document. If it outgrows the BSON
        // limit, switch to per-chat documents and publish a generation pointer.
        $bulk = new BulkWrite();
        $bulk->update(['key' => $this->key, 'run' => $run], [
            '$set' => [
                'chats' => $chats,
                'checked_at' => $checked_at,
                'expires_at' => new UTCDateTime((time() + self::MAX_AGE) * 1000),
            ],
        ]);
        $result = $this->Mongo->executeBulkWrite($this->database . '.' . self::COLLECTION, $bulk);
        if ($result->getMatchedCount() !== 1) {
            throw new RuntimeException('TG Members synchronization superseded.');
        }
    }

    public function revoke(): void
    {
        $bulk = new BulkWrite();
        $bulk->delete(['key' => $this->key], ['limit' => 1]);
        $this->Mongo->executeBulkWrite($this->database . '.' . self::COLLECTION, $bulk);
    }
}
