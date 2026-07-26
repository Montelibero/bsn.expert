<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use DateTimeInterface;
use MongoDB\BSON\UTCDateTime;

final class TelegramUsageAggregator
{
    /**
     * @param iterable<array<string, mixed>> $events
     * @return array{
     *     day_utc: string,
     *     totals: array{
     *         requests: int,
     *         unique_chats: int,
     *         unique_users: int,
     *         unique_accounts: int,
     *         anonymous_requests: int,
     *         known_requests: int,
     *         unknown_requests: int,
     *         outcomes: array<string, int>
     *     },
     *     accounts: list<array<string, mixed>>,
     *     chats: list<array<string, mixed>>,
     *     users: list<array<string, mixed>>
     * }
     */
    public function aggregate(string $day_utc, iterable $events): array
    {
        TelegramUsageStore::assertDay($day_utc);
        $requests = 0;
        $anonymous_requests = 0;
        $known_requests = 0;
        $unknown_requests = 0;
        $outcomes = [];
        $accounts = [];
        $chats = [];
        $users = [];

        foreach ($events as $event) {
            if (($event['day_utc'] ?? null) !== $day_utc
                || ($event['action'] ?? null) !== 'account_info'
            ) {
                continue;
            }
            $account_id = trim((string) ($event['account_id'] ?? ''));
            $chat = is_array($event['chat'] ?? null) ? $event['chat'] : [];
            $chat_id = trim((string) ($chat['id'] ?? ''));
            $outcome = trim((string) ($event['outcome'] ?? ''));
            if ($account_id === '' || $chat_id === '' || $outcome === '') {
                continue;
            }

            $requests++;
            $known = ($event['known'] ?? false) === true;
            if ($known) {
                $known_requests++;
            } else {
                $unknown_requests++;
            }
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            $occurred_at = $this->timestamp($event['occurred_at'] ?? null);
            $user = is_array($event['user'] ?? null) ? $event['user'] : null;
            $user_id = $user === null ? null : trim((string) ($user['id'] ?? ''));
            if ($user_id === '') {
                $user_id = null;
            }

            $account_key = $account_id;
            $accounts[$account_key] ??= [
                'account_id' => $account_id,
                'known' => false,
                'requests' => 0,
                'chats' => [],
                'users' => [],
                'outcomes' => [],
            ];
            $accounts[$account_key]['known'] = $accounts[$account_key]['known'] || $known;
            $accounts[$account_key]['requests']++;
            $accounts[$account_key]['chats'][$chat_id] = true;
            if ($user_id !== null) {
                $accounts[$account_key]['users'][$user_id] = true;
            }
            $accounts[$account_key]['outcomes'][$outcome] =
                ($accounts[$account_key]['outcomes'][$outcome] ?? 0) + 1;

            $chats[$chat_id] ??= [
                'chat_id' => $chat_id,
                'type' => (string) ($chat['type'] ?? ''),
                'title' => $this->optionalString($chat['title'] ?? null),
                'username' => $this->optionalString($chat['username'] ?? null),
                'requests' => 0,
                'users' => [],
                '_latest_at' => PHP_INT_MIN,
            ];
            $chats[$chat_id]['requests']++;
            if ($user_id !== null) {
                $chats[$chat_id]['users'][$user_id] = true;
            }
            if ($occurred_at >= $chats[$chat_id]['_latest_at']) {
                $chats[$chat_id]['type'] = (string) ($chat['type'] ?? '');
                $chats[$chat_id]['title'] = $this->optionalString($chat['title'] ?? null);
                $chats[$chat_id]['username'] = $this->optionalString($chat['username'] ?? null);
                $chats[$chat_id]['_latest_at'] = $occurred_at;
            }

            if ($user_id === null) {
                $anonymous_requests++;
                continue;
            }
            $users[$user_id] ??= [
                'user_id' => $user_id,
                'username' => $this->optionalString($user['username'] ?? null),
                'name' => $this->optionalString($user['name'] ?? null),
                'requests' => 0,
                'chats' => [],
                '_latest_at' => PHP_INT_MIN,
            ];
            $users[$user_id]['requests']++;
            $users[$user_id]['chats'][$chat_id] = true;
            if ($occurred_at >= $users[$user_id]['_latest_at']) {
                $users[$user_id]['username'] = $this->optionalString($user['username'] ?? null);
                $users[$user_id]['name'] = $this->optionalString($user['name'] ?? null);
                $users[$user_id]['_latest_at'] = $occurred_at;
            }
        }

        $account_rows = array_values(array_map(static function (array $account): array {
            $account['chat_count'] = count($account['chats']);
            $account['user_count'] = count($account['users']);
            unset($account['chats'], $account['users']);
            ksort($account['outcomes']);

            return $account;
        }, $accounts));
        usort($account_rows, static fn(array $left, array $right): int =>
            ((int) $right['requests'] <=> (int) $left['requests'])
            ?: strcmp((string) $left['account_id'], (string) $right['account_id'])
        );

        $chat_rows = array_values(array_map(static function (array $chat): array {
            $chat['user_count'] = count($chat['users']);
            unset($chat['users'], $chat['_latest_at']);

            return $chat;
        }, $chats));
        usort($chat_rows, static fn(array $left, array $right): int =>
            ((int) $right['requests'] <=> (int) $left['requests'])
            ?: strcmp((string) $left['chat_id'], (string) $right['chat_id'])
        );

        $user_rows = array_values(array_map(static function (array $user): array {
            $user['chat_count'] = count($user['chats']);
            unset($user['chats'], $user['_latest_at']);

            return $user;
        }, $users));
        usort($user_rows, static fn(array $left, array $right): int =>
            ((int) $right['requests'] <=> (int) $left['requests'])
            ?: strcmp((string) $left['user_id'], (string) $right['user_id'])
        );

        ksort($outcomes);

        return [
            'day_utc' => $day_utc,
            'totals' => [
                'requests' => $requests,
                'unique_chats' => count($chat_rows),
                'unique_users' => count($user_rows),
                'unique_accounts' => count($account_rows),
                'anonymous_requests' => $anonymous_requests,
                'known_requests' => $known_requests,
                'unknown_requests' => $unknown_requests,
                'outcomes' => $outcomes,
            ],
            'accounts' => $account_rows,
            'chats' => $chat_rows,
            'users' => $user_rows,
        ];
    }

    /**
     * @param array<string, mixed> $aggregate
     * @return array{
     *     day_utc: string,
     *     requests: int,
     *     unique_chats: int,
     *     unique_users: int,
     *     unique_accounts: int
     * }
     */
    public function anonymizedSummary(array $aggregate): array
    {
        $day_utc = (string) ($aggregate['day_utc'] ?? '');
        TelegramUsageStore::assertDay($day_utc);
        $totals = is_array($aggregate['totals'] ?? null) ? $aggregate['totals'] : [];

        return [
            'day_utc' => $day_utc,
            'requests' => max(0, (int) ($totals['requests'] ?? 0)),
            'unique_chats' => max(0, (int) ($totals['unique_chats'] ?? 0)),
            'unique_users' => max(0, (int) ($totals['unique_users'] ?? 0)),
            'unique_accounts' => max(0, (int) ($totals['unique_accounts'] ?? 0)),
        ];
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timestamp(mixed $value): int
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->getTimestamp();
        }
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_int($value) ? $value : 0;
    }
}
