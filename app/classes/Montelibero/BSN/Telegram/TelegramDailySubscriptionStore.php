<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class TelegramDailySubscriptionStore
{
    public const COLLECTION = 'telegram_daily_subscriptions';
    private const DEFAULT_LEASE_SECONDS = 300;

    /** @var list<string> */
    private array $admin_user_ids;

    /** @param list<string|int> $admin_user_ids */
    public function __construct(
        private readonly Manager $Mongo,
        private readonly string $database,
        array $admin_user_ids,
    ) {
        $ids = [];
        foreach ($admin_user_ids as $id) {
            $id = trim((string) $id);
            if (preg_match('/\A\d+\z/D', $id) === 1 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $this->admin_user_ids = $ids;
    }

    public function canManage(string|int $chat_id, string|int $user_id, string $chat_type): bool
    {
        $chat_id = trim((string) $chat_id);
        $user_id = trim((string) $user_id);

        return $chat_type === 'private'
            && preg_match('/\A\d+\z/D', $chat_id) === 1
            && preg_match('/\A\d+\z/D', $user_id) === 1
            && hash_equals($user_id, $chat_id)
            && in_array($user_id, $this->admin_user_ids, true);
    }

    /** Returns true only when the subscription state changed. */
    public function enable(
        string|int $chat_id,
        string|int $admin_user_id,
        string $chat_type,
        ?DateTimeImmutable $now = null,
    ): bool {
        $chat_id = trim((string) $chat_id);
        $admin_user_id = trim((string) $admin_user_id);
        $this->assertPrivateAdmin($chat_id, $admin_user_id, $chat_type);
        if ($this->isEnabled($chat_id)) {
            return false;
        }

        $Now = $this->utc($now);
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $chat_id],
            [
                '$set' => [
                    'chat_id' => $chat_id,
                    'admin_user_id' => $admin_user_id,
                    'enabled' => true,
                    // A newly enabled subscription starts with the next completed
                    // UTC day instead of immediately receiving yesterday's report.
                    'last_sent_day_utc' => $Now->setTime(0, 0)->modify('-1 day')->format('Y-m-d'),
                    'updated_at' => $this->mongoDate($Now),
                ],
                '$setOnInsert' => [
                    'created_at' => $this->mongoDate($Now),
                ],
                '$unset' => [
                    'claim_day_utc' => '',
                    'claim_token' => '',
                    'claim_until' => '',
                    'retry_after' => '',
                    'last_error_code' => '',
                ],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);

        return true;
    }

    /** Returns true only when an enabled subscription was removed. */
    public function disable(
        string|int $chat_id,
        string|int $admin_user_id,
        string $chat_type,
    ): bool {
        $chat_id = trim((string) $chat_id);
        $admin_user_id = trim((string) $admin_user_id);
        $this->assertPrivateAdmin($chat_id, $admin_user_id, $chat_type);

        $Bulk = new BulkWrite();
        $Bulk->delete(['_id' => $chat_id, 'enabled' => true], ['limit' => 1]);
        $Result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);

        return $Result->getDeletedCount() === 1;
    }

    public function isEnabled(string|int $chat_id): bool
    {
        $chat_id = trim((string) $chat_id);
        if (preg_match('/\A\d+\z/D', $chat_id) !== 1) {
            return false;
        }
        $Query = new Query(['_id' => $chat_id, 'enabled' => true], ['limit' => 1, 'projection' => ['_id' => 1]]);

        return current($this->Mongo->executeQuery($this->namespace(), $Query)->toArray()) !== false;
    }

    /**
     * Atomically leases one subscription that has not received the requested
     * UTC day. The lease prevents concurrent cron workers from obvious double
     * delivery; the caller must complete or fail the returned token.
     *
     * @return array{chat_id: string, admin_user_id: string, day_utc: string, claim_token: string}|null
     */
    public function claimNextDue(
        string $day_utc,
        ?DateTimeImmutable $now = null,
        int $lease_seconds = self::DEFAULT_LEASE_SECONDS,
    ): ?array {
        TelegramUsageStore::assertDay($day_utc);
        $Now = $this->utc($now);
        $MongoNow = $this->mongoDate($Now);
        $lease_seconds = max(30, $lease_seconds);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $filter = $this->dueFilter($day_utc, $MongoNow);
            $Query = new Query($filter, ['sort' => ['_id' => 1], 'limit' => 1]);
            $document = current($this->Mongo->executeQuery($this->namespace(), $Query)->toArray()) ?: null;
            if ($document === null) {
                return null;
            }
            $chat_id = trim((string) ($document->chat_id ?? $document->_id ?? ''));
            $admin_user_id = trim((string) ($document->admin_user_id ?? ''));
            if ($chat_id === '' || $admin_user_id === '') {
                throw new \RuntimeException('Invalid Telegram daily subscription document.');
            }

            $claim_token = bin2hex(random_bytes(16));
            $ClaimUntil = $Now->modify(sprintf('+%d seconds', $lease_seconds));
            $filter['_id'] = $chat_id;
            $Bulk = new BulkWrite();
            $Bulk->update(
                $filter,
                [
                    '$set' => [
                        'claim_day_utc' => $day_utc,
                        'claim_token' => $claim_token,
                        'claim_until' => $this->mongoDate($ClaimUntil),
                        'last_attempt_at' => $MongoNow,
                    ],
                ],
                ['limit' => 1]
            );
            $Result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
            if ($Result->getModifiedCount() === 1) {
                return [
                    'chat_id' => $chat_id,
                    'admin_user_id' => $admin_user_id,
                    'day_utc' => $day_utc,
                    'claim_token' => $claim_token,
                ];
            }
        }

        return null;
    }

    public function completeDelivery(
        string $chat_id,
        string $day_utc,
        string $claim_token,
        int|string|null $message_id,
        ?DateTimeImmutable $now = null,
    ): bool {
        TelegramUsageStore::assertDay($day_utc);
        $Now = $this->utc($now);
        $set = [
            'last_sent_day_utc' => $day_utc,
            'last_sent_at' => $this->mongoDate($Now),
            'updated_at' => $this->mongoDate($Now),
        ];
        if ($message_id !== null && preg_match('/\A\d+\z/D', (string) $message_id) === 1) {
            $set['last_message_id'] = (int) $message_id;
        }

        $Bulk = new BulkWrite();
        $Bulk->update(
            [
                '_id' => $chat_id,
                'enabled' => true,
                'claim_day_utc' => $day_utc,
                'claim_token' => $claim_token,
            ],
            [
                '$set' => $set,
                '$unset' => [
                    'claim_day_utc' => '',
                    'claim_token' => '',
                    'claim_until' => '',
                    'retry_after' => '',
                    'last_error_code' => '',
                ],
            ],
            ['limit' => 1]
        );
        $Result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);

        return $Result->getModifiedCount() === 1;
    }

    public function failDelivery(
        string $chat_id,
        string $day_utc,
        string $claim_token,
        ?int $error_code = null,
        ?DateTimeImmutable $retry_after = null,
        ?DateTimeImmutable $now = null,
    ): bool {
        TelegramUsageStore::assertDay($day_utc);
        $Now = $this->utc($now);
        $RetryAfter = ($retry_after ?? $Now->modify('+15 minutes'))->setTimezone(new DateTimeZone('UTC'));
        $set = [
            'last_failure_at' => $this->mongoDate($Now),
            'retry_after' => $this->mongoDate($RetryAfter),
            'updated_at' => $this->mongoDate($Now),
        ];
        if ($error_code !== null) {
            $set['last_error_code'] = $error_code;
        }
        $update = [
            '$set' => $set,
            '$unset' => [
                'claim_day_utc' => '',
                'claim_token' => '',
                'claim_until' => '',
            ],
        ];
        if ($error_code === null) {
            $update['$unset']['last_error_code'] = '';
        }

        $Bulk = new BulkWrite();
        $Bulk->update(
            [
                '_id' => $chat_id,
                'enabled' => true,
                'claim_day_utc' => $day_utc,
                'claim_token' => $claim_token,
            ],
            $update,
            ['limit' => 1]
        );
        $Result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);

        return $Result->getModifiedCount() === 1;
    }

    /**
     * Records a transport-level uncertainty as terminal for this UTC day. A
     * retry could duplicate a report which Telegram may already have accepted.
     */
    public function deliveryUncertain(
        string $chat_id,
        string $day_utc,
        string $claim_token,
        ?int $error_code = null,
        ?DateTimeImmutable $now = null,
    ): bool {
        TelegramUsageStore::assertDay($day_utc);
        $Now = $this->utc($now);
        $set = [
            'last_sent_day_utc' => $day_utc,
            'last_delivery_uncertain_at' => $this->mongoDate($Now),
            'updated_at' => $this->mongoDate($Now),
        ];
        if ($error_code !== null) {
            $set['last_error_code'] = $error_code;
        }
        $update = [
            '$set' => $set,
            '$unset' => [
                'claim_day_utc' => '',
                'claim_token' => '',
                'claim_until' => '',
                'retry_after' => '',
            ],
        ];
        if ($error_code === null) {
            $update['$unset']['last_error_code'] = '';
        }

        $Bulk = new BulkWrite();
        $Bulk->update(
            [
                '_id' => $chat_id,
                'enabled' => true,
                'claim_day_utc' => $day_utc,
                'claim_token' => $claim_token,
            ],
            $update,
            ['limit' => 1]
        );
        $Result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);

        return $Result->getModifiedCount() === 1;
    }

    private function assertPrivateAdmin(string $chat_id, string $user_id, string $chat_type): void
    {
        if (!$this->canManage($chat_id, $user_id, $chat_type)) {
            throw new \RuntimeException('Telegram daily reports are available only in private admin chats.');
        }
    }

    private function dueFilter(string $day_utc, UTCDateTime $Now): array
    {
        return [
            'enabled' => true,
            // Removing a user from ADMINS_TG must immediately revoke future
            // report delivery, including subscriptions enabled in the past.
            'admin_user_id' => ['$in' => $this->admin_user_ids],
            '$and' => [
                ['$or' => [
                    ['last_sent_day_utc' => ['$exists' => false]],
                    ['last_sent_day_utc' => null],
                    ['last_sent_day_utc' => ['$lt' => $day_utc]],
                ]],
                ['$or' => [
                    ['claim_until' => ['$exists' => false]],
                    ['claim_until' => null],
                    ['claim_until' => ['$lte' => $Now]],
                ]],
                ['$or' => [
                    ['retry_after' => ['$exists' => false]],
                    ['retry_after' => null],
                    ['retry_after' => ['$lte' => $Now]],
                ]],
            ],
        ];
    }

    private function utc(?DateTimeImmutable $Date): DateTimeImmutable
    {
        return ($Date ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function mongoDate(DateTimeInterface $Date): UTCDateTime
    {
        return new UTCDateTime(((int) $Date->format('U')) * 1000);
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, self::COLLECTION);
    }
}
