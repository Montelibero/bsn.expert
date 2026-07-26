<?php

declare(strict_types=1);

use Montelibero\BSN\Telegram\TelegramBotApiException;
use Montelibero\BSN\Telegram\TelegramDailyReportRenderer;
use Montelibero\BSN\Telegram\TelegramDailyReportService;
use Montelibero\BSN\Telegram\TelegramDailySubscriptionStore;
use Montelibero\BSN\Telegram\TelegramUsageAggregator;
use Montelibero\BSN\Telegram\TelegramUsageStore;
use MongoDB\Driver\Manager;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertTelegramDaily(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

/** @return list<array<string, mixed>> */
function telegramDailyBlocksOfType(mixed $value, string $type): array
{
    if (!is_array($value)) {
        return [];
    }

    $found = [];
    if (!array_is_list($value) && ($value['type'] ?? null) === $type) {
        $found[] = $value;
    }
    foreach ($value as $child) {
        array_push($found, ...telegramDailyBlocksOfType($child, $type));
    }

    return $found;
}

function telegramDailyPlainText(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }
    if (!is_array($value)) {
        return '';
    }

    return implode('', array_map(telegramDailyPlainText(...), array_values($value)));
}

final class TelegramDailyFakeUsageStore extends TelegramUsageStore
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $events;

    /** @var array<string, array<string, mixed>> */
    public array $summaries = [];

    /** @param array<string, list<array<string, mixed>>> $events */
    public function __construct(array $events)
    {
        $this->events = $events;
    }

    public function eventsForDay(string $day_utc): iterable
    {
        TelegramUsageStore::assertDay($day_utc);

        return $this->events[$day_utc] ?? [];
    }

    /** @param array<string, mixed> $event */
    public function appendEvent(string $day_utc, array $event): void
    {
        TelegramUsageStore::assertDay($day_utc);
        $this->events[$day_utc][] = $event;
    }

    public function finalizeSummary(array $summary, ?DateTimeImmutable $finalized_at = null): bool
    {
        $day_utc = (string) ($summary['day_utc'] ?? '');
        TelegramUsageStore::assertDay($day_utc);

        $FinalizedAt = ($finalized_at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $existing = $this->summaries[$day_utc] ?? null;
        $next = $summary + [
            'finalized_at' => $FinalizedAt,
            'first_finalized_at' => is_array($existing)
                ? ($existing['first_finalized_at'] ?? $FinalizedAt)
                : $FinalizedAt,
        ];
        $changed = $existing === null || $existing != $next;
        $this->summaries[$day_utc] = $next;

        return $changed;
    }

    public function summaryForDay(string $day_utc): ?array
    {
        TelegramUsageStore::assertDay($day_utc);

        return $this->summaries[$day_utc] ?? null;
    }

    public function summariesBetween(string $from_day_utc, string $to_day_utc): array
    {
        TelegramUsageStore::assertDay($from_day_utc);
        TelegramUsageStore::assertDay($to_day_utc);

        return array_filter(
            $this->summaries,
            static fn(string $day_utc): bool => $day_utc >= $from_day_utc && $day_utc <= $to_day_utc,
            ARRAY_FILTER_USE_KEY
        );
    }
}

final class TelegramDailyFakeSubscriptionStore extends TelegramDailySubscriptionStore
{
    /** @var list<array{chat_id: string, admin_user_id: string, day_utc: string, claim_token: string}> */
    private array $claims;

    /** @var list<array<string, mixed>> */
    public array $completed = [];

    /** @var list<array<string, mixed>> */
    public array $failed = [];

    /** @var list<array<string, mixed>> */
    public array $uncertain = [];

    /** @param list<array{chat_id: string, admin_user_id: string, day_utc: string, claim_token: string}> $claims */
    public function __construct(array $claims)
    {
        $this->claims = $claims;
    }

    public function claimNextDue(
        string $day_utc,
        ?DateTimeImmutable $now = null,
        int $lease_seconds = 300,
    ): ?array {
        $claim = array_shift($this->claims);
        if ($claim === null) {
            return null;
        }
        $claim['day_utc'] = $day_utc;

        return $claim;
    }

    public function completeDelivery(
        string $chat_id,
        string $day_utc,
        string $claim_token,
        int|string|null $message_id,
        ?DateTimeImmutable $now = null,
    ): bool {
        $this->completed[] = compact('chat_id', 'day_utc', 'claim_token', 'message_id', 'now');

        return true;
    }

    public function failDelivery(
        string $chat_id,
        string $day_utc,
        string $claim_token,
        ?int $error_code = null,
        ?DateTimeImmutable $retry_after = null,
        ?DateTimeImmutable $now = null,
    ): bool {
        $this->failed[] = compact(
            'chat_id',
            'day_utc',
            'claim_token',
            'error_code',
            'retry_after',
            'now'
        );

        return true;
    }

    public function deliveryUncertain(
        string $chat_id,
        string $day_utc,
        string $claim_token,
        ?int $error_code = null,
        ?DateTimeImmutable $now = null,
    ): bool {
        $this->uncertain[] = compact('chat_id', 'day_utc', 'claim_token', 'error_code', 'now');

        return true;
    }
}

$day_utc = '2026-07-25';
$first_account = 'GDUMK6YJZ6ZC72CAMVHLUHLIFTNSLD7WFWO75Q3T2EOEW75XWH4PNSOZ';
$second_account = 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF';
$events = [
    [
        'day_utc' => $day_utc,
        'occurred_at' => new DateTimeImmutable('2026-07-25T10:00:00Z'),
        'action' => 'account_info',
        'account_id' => $first_account,
        'known' => true,
        'outcome' => 'sent',
        'chat' => [
            'id' => '-1001',
            'type' => 'supergroup',
            'title' => 'Old title',
            'username' => 'old_chat',
        ],
        'user' => ['id' => '10', 'username' => 'alice', 'name' => 'Alice'],
    ],
    [
        'day_utc' => $day_utc,
        'occurred_at' => new DateTimeImmutable('2026-07-25T12:00:00Z'),
        'action' => 'account_info',
        'account_id' => $first_account,
        'known' => true,
        'outcome' => 'sent',
        'chat' => [
            'id' => '-1001',
            'type' => 'supergroup',
            'title' => 'Montelibero',
            'username' => 'mtl_chat',
        ],
        'user' => ['id' => '11', 'username' => 'bob', 'name' => 'Bob'],
    ],
    [
        'day_utc' => $day_utc,
        'occurred_at' => new DateTimeImmutable('2026-07-25T13:00:00Z'),
        'action' => 'account_info',
        'account_id' => $second_account,
        'known' => false,
        'outcome' => 'not_in_bsn',
        'chat' => ['id' => '10', 'type' => 'private', 'title' => null, 'username' => null],
        'user' => ['id' => '10', 'username' => 'alice_new', 'name' => 'Alice Updated'],
    ],
    [
        'day_utc' => '2026-07-24',
        'occurred_at' => new DateTimeImmutable('2026-07-24T23:59:59Z'),
        'action' => 'account_info',
        'account_id' => $second_account,
        'known' => false,
        'outcome' => 'ignored_wrong_day',
        'chat' => ['id' => '999', 'type' => 'private'],
        'user' => null,
    ],
];

$Aggregator = new TelegramUsageAggregator();
$AuthorizationStore = new TelegramDailySubscriptionStore(
    new Manager('mongodb://127.0.0.1:27017/?connectTimeoutMS=1'),
    'telegram_daily_test',
    ['3718221']
);
assertTelegramDaily(
    true,
    $AuthorizationStore->canManage('3718221', '3718221', 'private'),
    'A configured admin may manage reports in their own private chat.'
);
assertTelegramDaily(
    false,
    $AuthorizationStore->canManage('-1001', '3718221', 'supergroup'),
    'Subscriptions must not be managed from a group.'
);
assertTelegramDaily(
    false,
    $AuthorizationStore->canManage('999', '3718221', 'private'),
    'An admin must not subscribe a different private chat.'
);
$aggregate = $Aggregator->aggregate($day_utc, $events);
assertTelegramDaily(3, $aggregate['totals']['requests'], 'Only events from the requested UTC day must count.');
assertTelegramDaily(2, $aggregate['totals']['unique_chats'], 'Unique chats must be counted.');
assertTelegramDaily(2, $aggregate['totals']['unique_users'], 'Unique users must be counted.');
assertTelegramDaily(2, $aggregate['totals']['unique_accounts'], 'Unique accounts must be counted.');
assertTelegramDaily(2, $aggregate['totals']['known_requests'], 'Known account requests must be counted.');
assertTelegramDaily(1, $aggregate['totals']['unknown_requests'], 'Unknown account requests must be counted.');
assertTelegramDaily($first_account, $aggregate['accounts'][0]['account_id'], 'Popular accounts must sort by request count.');
assertTelegramDaily('Montelibero', $aggregate['chats'][0]['title'], 'The latest chat title must be retained.');
assertTelegramDaily('mtl_chat', $aggregate['chats'][0]['username'], 'The latest public chat username must be retained.');
assertTelegramDaily('alice_new', $aggregate['users'][0]['username'], 'The latest user metadata must be retained.');

$summary = $Aggregator->anonymizedSummary($aggregate);
assertTelegramDaily(
    ['day_utc', 'requests', 'unique_chats', 'unique_users', 'unique_accounts'],
    array_keys($summary),
    'The permanent summary must have only anonymized totals.'
);
$summary_json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
assertTelegramDaily(false, str_contains($summary_json, $first_account), 'Permanent summaries must not contain account IDs.');
assertTelegramDaily(false, str_contains($summary_json, 'Montelibero'), 'Permanent summaries must not contain chat names.');
assertTelegramDaily(false, str_contains($summary_json, 'alice'), 'Permanent summaries must not contain usernames.');

$Renderer = new TelegramDailyReportRenderer();
$rendered = $Renderer->render($aggregate, new DateTimeImmutable('2026-07-26T00:15:00Z'));
$blocks = $rendered['rich_message']['blocks'];
assertTelegramDaily('heading', $blocks[0]['type'] ?? null, 'The report must start with a heading.');
assertTelegramDaily('Суточный отчёт BSN Robot', $blocks[0]['text'] ?? null, 'The report must have a descriptive title.');
assertTelegramDaily(false, array_key_exists('skip_entity_detection', $rendered['rich_message']), 'Hashtag entity detection must stay enabled.');
assertTelegramDaily(true, $rendered['stats']['blocks'] <= 400, 'The report must fit the block budget.');
assertTelegramDaily(true, $rendered['stats']['characters'] <= 28000, 'The report must fit the text budget.');
$details = telegramDailyBlocksOfType($blocks, 'details');
assertTelegramDaily(3, count($details), 'Accounts, chats, and users must each use one details block.');
foreach ($details as $detail) {
    assertTelegramDaily(false, ($detail['is_open'] ?? false) === true, 'Daily report details must be collapsed.');
    assertTelegramDaily(
        'blockquote',
        $detail['blocks'][0]['type'] ?? null,
        'Each collapsed detail must contain a block quote.'
    );
}
$plain_text = telegramDailyPlainText($rendered['rich_message']);
assertTelegramDaily(true, str_contains($plain_text, '#daily_report'), 'The hashtag must be present in visible text.');
assertTelegramDaily(true, str_contains($plain_text, '2026-07-25 UTC'), 'The report must identify the UTC day.');
assertTelegramDaily(true, str_contains($plain_text, '@mtl_chat'), 'Active chats must include their public username.');

$empty = $Aggregator->aggregate('2026-07-24', []);
$empty_rendered = $Renderer->render($empty, new DateTimeImmutable('2026-07-25T00:05:00Z'));
assertTelegramDaily(
    3,
    count(telegramDailyBlocksOfType($empty_rendered['rich_message']['blocks'], 'details')),
    'An empty day must still produce all report sections.'
);
assertTelegramDaily(
    true,
    str_contains(telegramDailyPlainText($empty_rendered['rich_message']), 'Нет данных.'),
    'An empty daily report must say that its sections have no data.'
);

$UsageStore = new TelegramDailyFakeUsageStore([$day_utc => $events]);
$SubscriptionStore = new TelegramDailyFakeSubscriptionStore([[
    'chat_id' => '3718221',
    'admin_user_id' => '3718221',
    'day_utc' => $day_utc,
    'claim_token' => 'first-claim',
]]);
$Service = new TelegramDailyReportService($UsageStore, $Aggregator, $SubscriptionStore, $Renderer);
$sent_calls = [];
$run = $Service->runDue(
    static function (string $chat_id, array $rich_message, array $options) use (&$sent_calls): array {
        $sent_calls[] = compact('chat_id', 'rich_message', 'options');

        return ['message_id' => 777];
    },
    new DateTimeImmutable('2026-07-26T01:00:00+00:00'),
    2,
    10
);
assertTelegramDaily($day_utc, $run['day_utc'], 'The service must report the previous UTC day.');
assertTelegramDaily(1, $run['sent'], 'A successfully completed subscription must count as sent.');
assertTelegramDaily(0, $run['failed'], 'A successful dispatch must not count as failed.');
assertTelegramDaily(true, $sent_calls[0]['options']['disable_notification'], 'Daily reports must be sent silently.');
assertTelegramDaily(
    true,
    str_contains(telegramDailyPlainText($sent_calls[0]['rich_message']), '#daily_report'),
    'The service must send the searchable report hashtag.'
);
assertTelegramDaily(777, $SubscriptionStore->completed[0]['message_id'], 'The Telegram message ID must complete the lease.');
assertTelegramDaily(['2026-07-24', '2026-07-25'], $run['finalized_days'], 'Missing completed days must finalize oldest first.');

$first_finalized_at = $UsageStore->summaries[$day_utc]['first_finalized_at'];
$UsageStore->appendEvent($day_utc, [
    'day_utc' => $day_utc,
    'occurred_at' => new DateTimeImmutable('2026-07-25T14:00:00Z'),
    'action' => 'account_info',
    'account_id' => $second_account,
    'known' => false,
    'outcome' => 'not_in_bsn',
    'chat' => ['id' => '10', 'type' => 'private', 'title' => null, 'username' => null],
    'user' => ['id' => '10', 'username' => 'alice_new', 'name' => 'Alice Updated'],
]);
$refresh_time = new DateTimeImmutable('2026-07-26T02:00:00Z');
$refreshed = $Service->finalizeDay($day_utc, $refresh_time);
$refreshed_summary = $summary;
$refreshed_summary['requests'] = 4;
assertTelegramDaily(true, $refreshed['created'], 'A late successful reply must refresh the stored summary.');
assertTelegramDaily($refreshed_summary, $refreshed['summary'], 'The refreshed summary must include the late reply.');
assertTelegramDaily(
    $first_finalized_at,
    $UsageStore->summaries[$day_utc]['first_finalized_at'],
    'Refreshing a summary must preserve its first finalization time.'
);

$unchanged_refresh = $Service->finalizeDay($day_utc, $refresh_time);
assertTelegramDaily(false, $unchanged_refresh['created'], 'An exact summary refresh retry must be idempotent.');
assertTelegramDaily($refreshed_summary, $unchanged_refresh['summary'], 'An exact refresh retry must keep current totals.');

$admin_rows = $Service->adminSummaries(new DateTimeImmutable('2026-07-26T14:00:00Z'));
assertTelegramDaily(14, count($admin_rows), 'Admin statistics must cover fourteen days including today.');
assertTelegramDaily('2026-07-26', $admin_rows[0]['day_utc'], 'Admin statistics must start with today.');
assertTelegramDaily('2026-07-13', $admin_rows[13]['day_utc'], 'Admin statistics must include thirteen preceding days.');
assertTelegramDaily(false, $admin_rows[0]['finalized'], 'Today must remain live and unfinalized.');
assertTelegramDaily(true, $admin_rows[1]['finalized'], 'A stored previous-day summary must be marked finalized.');

$day_details = $Service->adminDayDetails($day_utc);
assertTelegramDaily(true, $day_details['details_available'], 'Recent raw daily details must be available.');
assertTelegramDaily(2, count($day_details['aggregate']['accounts']), 'Chosen-day details must expose account rows.');

$ExpiredUsageStore = new TelegramDailyFakeUsageStore([]);
$ExpiredUsageStore->summaries[$day_utc] = $refreshed_summary;
$ExpiredService = new TelegramDailyReportService(
    $ExpiredUsageStore,
    $Aggregator,
    new TelegramDailyFakeSubscriptionStore([]),
    $Renderer
);
$expired_details = $ExpiredService->adminDayDetails(
    $day_utc,
    new DateTimeImmutable('2026-09-01T00:00:00Z')
);
assertTelegramDaily(false, $expired_details['details_available'], 'Expired raw details must not masquerade as an empty active day.');
assertTelegramDaily(4, $expired_details['summary']['requests'], 'Refreshed permanent totals must survive raw detail expiry.');

$FailedUsageStore = new TelegramDailyFakeUsageStore([$day_utc => $events]);
$FailedSubscriptions = new TelegramDailyFakeSubscriptionStore([[
    'chat_id' => '3718221',
    'admin_user_id' => '3718221',
    'day_utc' => $day_utc,
    'claim_token' => 'failed-claim',
]]);
$FailedService = new TelegramDailyReportService(
    $FailedUsageStore,
    $Aggregator,
    $FailedSubscriptions,
    $Renderer
);
$failed_run = $FailedService->runDue(
    static function (): array {
        throw new TelegramBotApiException(
            'Rate limited.',
            error_code: 429,
            retry_after: 120,
            api_method: 'sendRichMessage'
        );
    },
    new DateTimeImmutable('2026-07-26T01:00:00Z'),
    1,
    10
);
assertTelegramDaily(0, $failed_run['sent'], 'A failed API call must not complete delivery.');
assertTelegramDaily(1, $failed_run['failed'], 'A failed API call must be scheduled for retry.');
assertTelegramDaily(429, $FailedSubscriptions->failed[0]['error_code'], 'Telegram error codes must be retained for operations.');
assertTelegramDaily(
    '2026-07-26T01:02:00+00:00',
    $FailedSubscriptions->failed[0]['retry_after']->format(DateTimeInterface::ATOM),
    'Telegram retry_after must control the next attempt.'
);

$UncertainSubscriptions = new TelegramDailyFakeSubscriptionStore([[
    'chat_id' => '3718221',
    'admin_user_id' => '3718221',
    'day_utc' => $day_utc,
    'claim_token' => 'uncertain-claim',
]]);
$UncertainService = new TelegramDailyReportService(
    new TelegramDailyFakeUsageStore([$day_utc => $events]),
    $Aggregator,
    $UncertainSubscriptions,
    $Renderer
);
$uncertain_run = $UncertainService->runDue(
    static function (): array {
        throw new TelegramBotApiException(
            'Transport failed after write.',
            delivery_uncertain: true,
            api_method: 'sendRichMessage'
        );
    },
    new DateTimeImmutable('2026-07-26T01:00:00+00:00'),
    1,
    10
);
assertTelegramDaily(1, $uncertain_run['uncertain'], 'An uncertain report delivery must be terminal.');
assertTelegramDaily(0, $uncertain_run['failed'], 'An uncertain report must not be scheduled for retry.');
assertTelegramDaily(1, count($UncertainSubscriptions->uncertain), 'The uncertain lease must be recorded.');
assertTelegramDaily([], $UncertainSubscriptions->failed, 'Uncertain delivery must not use the retry path.');

fwrite(STDOUT, "Telegram daily report tests passed.\n");
