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
use Montelibero\BSN\BSN;

class TelegramUsageStore
{
    public const EVENTS_COLLECTION = 'telegram_usage_events';
    public const SUMMARIES_COLLECTION = 'telegram_daily_summaries';
    public const RAW_RETENTION_DAYS = 30;

    public function __construct(
        private readonly Manager $Mongo,
        private readonly string $database,
    ) {
    }

    /**
     * Persists one successfully answered account lookup. Telegram retries are
     * deduplicated by the update ID stored as the Mongo document ID.
     *
     * @param array{id: string|int, type: string, title?: ?string, username?: ?string} $chat
     * @param array{id: string|int, username?: ?string, name?: ?string}|null $user
     */
    public function recordAccountLookup(
        string|int $update_id,
        int $message_date,
        array $chat,
        ?array $user,
        string $account_id,
        string $outcome,
        bool $known,
        ?DateTimeImmutable $recorded_at = null,
    ): bool {
        $update_id = $this->numericId($update_id, 'Telegram update ID');
        if ($message_date < 1) {
            throw new \InvalidArgumentException('Telegram message date must be a positive Unix timestamp.');
        }

        $account_id = strtoupper(trim($account_id));
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            throw new \InvalidArgumentException('Invalid Stellar account ID for Telegram usage event.');
        }
        $outcome = trim($outcome);
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $outcome) !== 1) {
            throw new \InvalidArgumentException('Invalid Telegram lookup outcome.');
        }

        $chat_id = $this->numericId($chat['id'] ?? '', 'Telegram chat ID');
        $chat_type = (string) ($chat['type'] ?? '');
        if (!in_array($chat_type, ['private', 'group', 'supergroup'], true)) {
            throw new \InvalidArgumentException('Unsupported Telegram chat type for usage event.');
        }

        $User = null;
        if ($user !== null) {
            $User = [
                'id' => $this->numericId($user['id'] ?? '', 'Telegram user ID'),
                'username' => $this->cleanText($user['username'] ?? null, 64),
                'name' => $this->cleanText($user['name'] ?? null, 128),
            ];
        }

        $RecordedAt = ($recorded_at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $OccurredAt = (new DateTimeImmutable('@' . $message_date))->setTimezone(new DateTimeZone('UTC'));
        $ExpiresAt = $OccurredAt->modify(sprintf('+%d days', self::RAW_RETENTION_DAYS));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $update_id],
            [
                '$setOnInsert' => [
                    '_id' => $update_id,
                    'schema_version' => 1,
                    'day_utc' => $OccurredAt->format('Y-m-d'),
                    'occurred_at' => $this->mongoDate($OccurredAt),
                    'recorded_at' => $this->mongoDate($RecordedAt),
                    'expires_at' => $this->mongoDate($ExpiresAt),
                    'action' => 'account_info',
                    'account_id' => $account_id,
                    'outcome' => $outcome,
                    'known' => $known,
                    'chat' => [
                        'id' => $chat_id,
                        'type' => $chat_type,
                        'title' => $this->cleanText($chat['title'] ?? null, 128),
                        'username' => $this->cleanText($chat['username'] ?? null, 64),
                    ],
                    'user' => $User,
                ],
            ],
            ['upsert' => true]
        );
        $Result = $this->Mongo->executeBulkWrite($this->eventsNamespace(), $Bulk);

        return $Result->getUpsertedCount() === 1;
    }

    /** @return iterable<array<string, mixed>> */
    public function eventsForDay(string $day_utc): iterable
    {
        self::assertDay($day_utc);
        $Query = new Query(
            ['day_utc' => $day_utc, 'action' => 'account_info'],
            ['sort' => ['occurred_at' => 1, '_id' => 1]]
        );
        $Cursor = $this->Mongo->executeQuery($this->eventsNamespace(), $Query);
        foreach ($Cursor as $document) {
            $event = $this->normalizeMongoValue($document);
            if (is_array($event)) {
                yield $event;
            }
        }
    }

    /**
     * Upserts an anonymized daily total while the detailed retention window is
     * still open. This lets late successful replies reach permanent totals.
     *
     * @param array{
     *     day_utc: string,
     *     requests: int,
     *     unique_chats: int,
     *     unique_users: int,
     *     unique_accounts: int
     * } $summary
     */
    public function finalizeSummary(
        array $summary,
        ?DateTimeImmutable $finalized_at = null,
    ): bool {
        $day_utc = (string) ($summary['day_utc'] ?? '');
        self::assertDay($day_utc);
        foreach (['requests', 'unique_chats', 'unique_users', 'unique_accounts'] as $field) {
            if (!is_int($summary[$field] ?? null) || $summary[$field] < 0) {
                throw new \InvalidArgumentException(sprintf('Invalid Telegram daily summary field "%s".', $field));
            }
        }

        $FinalizedAt = ($finalized_at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $Bulk = new BulkWrite();
        $Bulk->update(
            ['_id' => $day_utc],
            [
                '$set' => [
                    'day_utc' => $day_utc,
                    'requests' => $summary['requests'],
                    'unique_chats' => $summary['unique_chats'],
                    'unique_users' => $summary['unique_users'],
                    'unique_accounts' => $summary['unique_accounts'],
                    'finalized_at' => $this->mongoDate($FinalizedAt),
                ],
                '$setOnInsert' => [
                    '_id' => $day_utc,
                    'first_finalized_at' => $this->mongoDate($FinalizedAt),
                ],
            ],
            ['upsert' => true]
        );
        $Result = $this->Mongo->executeBulkWrite($this->summariesNamespace(), $Bulk);

        return $Result->getUpsertedCount() === 1 || $Result->getModifiedCount() === 1;
    }

    /** @return array<string, mixed>|null */
    public function summaryForDay(string $day_utc): ?array
    {
        self::assertDay($day_utc);
        $Query = new Query(['_id' => $day_utc], ['limit' => 1]);
        $document = current($this->Mongo->executeQuery($this->summariesNamespace(), $Query)->toArray()) ?: null;
        if ($document === null) {
            return null;
        }

        $summary = $this->normalizeMongoValue($document);

        return is_array($summary) ? $summary : null;
    }

    /** @return array<string, array<string, mixed>> keyed by UTC day */
    public function summariesBetween(string $from_day_utc, string $to_day_utc): array
    {
        self::assertDay($from_day_utc);
        self::assertDay($to_day_utc);
        if ($from_day_utc > $to_day_utc) {
            throw new \InvalidArgumentException('Telegram summary start day must not be after end day.');
        }

        $Query = new Query(
            ['_id' => ['$gte' => $from_day_utc, '$lte' => $to_day_utc]],
            ['sort' => ['_id' => 1]]
        );
        $summaries = [];
        foreach ($this->Mongo->executeQuery($this->summariesNamespace(), $Query) as $document) {
            $summary = $this->normalizeMongoValue($document);
            if (!is_array($summary)) {
                continue;
            }
            $day_utc = (string) ($summary['day_utc'] ?? $summary['_id'] ?? '');
            if ($day_utc !== '') {
                $summaries[$day_utc] = $summary;
            }
        }

        return $summaries;
    }

    public static function assertDay(string $day_utc): void
    {
        $Date = DateTimeImmutable::createFromFormat('!Y-m-d', $day_utc, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($Date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $Date->format('Y-m-d') !== $day_utc
        ) {
            throw new \InvalidArgumentException('Invalid UTC day; expected YYYY-MM-DD.');
        }
    }

    private function numericId(string|int $value, string $label): string
    {
        $value = trim((string) $value);
        if (preg_match('/\A-?\d+\z/D', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must be an integer.');
        }

        return $value;
    }

    private function cleanText(mixed $value, int $max_length): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max_length, 'UTF-8');
    }

    private function mongoDate(DateTimeInterface $Date): UTCDateTime
    {
        return new UTCDateTime(((int) $Date->format('U')) * 1000);
    }

    private function eventsNamespace(): string
    {
        return sprintf('%s.%s', $this->database, self::EVENTS_COLLECTION);
    }

    private function summariesNamespace(): string
    {
        return sprintf('%s.%s', $this->database, self::SUMMARIES_COLLECTION);
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
