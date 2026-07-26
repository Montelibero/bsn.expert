<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\KnownTagsCatalog;
use Montelibero\BSN\Knowledge\AccountReportBuilder;
use Montelibero\BSN\RequestLocale;
use Montelibero\BSN\Telegram\AccountRichMessageRenderer;
use Montelibero\BSN\Telegram\TelegramBotApiClient;
use Montelibero\BSN\Telegram\TelegramBotConfig;
use Montelibero\BSN\Telegram\TelegramDailySubscriptionStore;
use Montelibero\BSN\Telegram\TelegramUpdateParser;
use Montelibero\BSN\Telegram\TelegramUpdateProcessor;
use Montelibero\BSN\Telegram\TelegramUpdateStore;
use Montelibero\BSN\Telegram\TelegramUsageStore;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

final class TelegramProcessorAccountsManager extends AccountsManager
{
    public function __construct()
    {
    }

    public function fetchUsernames(): array
    {
        return [];
    }
}

final class TelegramProcessorDocumentsManager extends DocumentsManager
{
    public function __construct()
    {
    }

    public function getDocuments(?string $source = null): array
    {
        return [];
    }
}

final class TelegramProcessorUpdateStore extends TelegramUpdateStore
{
    /** @var list<array<string, mixed>> */
    public array $jobs;
    /** @var list<array<string, mixed>> */
    public array $completed = [];
    /** @var list<array<string, mixed>> */
    public array $retried = [];
    /** @var list<array<string, mixed>> */
    public array $failed = [];
    /** @var list<array<string, mixed>> */
    public array $uncertain = [];
    /** @var list<array<string, mixed>> */
    public array $usage_pending = [];

    /** @param list<array<string, mixed>> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    public function claimNextDue(): ?array
    {
        return array_shift($this->jobs);
    }

    public function complete(string $update_id, string $lease_token, array $effect = []): bool
    {
        $this->completed[] = compact('update_id', 'lease_token', 'effect');

        return true;
    }

    public function markUsagePending(
        string $update_id,
        string $lease_token,
        array $usage,
        array $effect,
        bool $success_reaction,
    ): bool {
        $this->usage_pending[] = compact(
            'update_id',
            'lease_token',
            'usage',
            'effect',
            'success_reaction'
        );

        return true;
    }

    public function retry(string $update_id, string $lease_token, string $error, int $delay_seconds): bool
    {
        $this->retried[] = compact('update_id', 'lease_token', 'error', 'delay_seconds');

        return true;
    }

    public function fail(string $update_id, string $lease_token, string $error): bool
    {
        $this->failed[] = compact('update_id', 'lease_token', 'error');

        return true;
    }

    public function deliveryUncertain(string $update_id, string $lease_token, string $error): bool
    {
        $this->uncertain[] = compact('update_id', 'lease_token', 'error');

        return true;
    }
}

final class TelegramProcessorUsageStore extends TelegramUsageStore
{
    /** @var list<array<string, mixed>> */
    public array $events = [];
    public int $attempts = 0;

    public function __construct(private int $failures_remaining = 0)
    {
    }

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
        $this->attempts++;
        if ($this->failures_remaining > 0) {
            $this->failures_remaining--;

            throw new RuntimeException('Simulated Telegram usage storage failure.');
        }

        $this->events[] = compact(
            'update_id',
            'message_date',
            'chat',
            'user',
            'account_id',
            'outcome',
            'known'
        );

        return true;
    }
}

final class TelegramProcessorSubscriptionStore extends TelegramDailySubscriptionStore
{
    public bool $enabled = false;

    public function __construct(private readonly string $admin_id = '42')
    {
    }

    public function canManage(string|int $chat_id, string|int $user_id, string $chat_type): bool
    {
        return (string) $chat_id === $this->admin_id
            && (string) $user_id === $this->admin_id
            && $chat_type === 'private';
    }

    public function enable(
        string|int $chat_id,
        string|int $admin_user_id,
        string $chat_type,
        ?DateTimeImmutable $now = null,
    ): bool {
        $changed = !$this->enabled;
        $this->enabled = true;

        return $changed;
    }

    public function disable(string|int $chat_id, string|int $admin_user_id, string $chat_type): bool
    {
        $changed = $this->enabled;
        $this->enabled = false;

        return $changed;
    }
}

function assertTelegramProcessor(mixed $expected, mixed $actual, string $message): void
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

/** @return array<string, mixed> */
function telegramProcessorPayload(
    string $update_id,
    string $type,
    string $command,
    ?string $account_id = null,
    ?string $validation_error = null,
): array {
    return [
        'update_id' => $update_id,
        'type' => $type,
        'command' => $command,
        'account_id' => $account_id,
        'validation_error' => $validation_error,
        'chat' => [
            'id' => '42',
            'type' => 'private',
            'title' => null,
            'username' => 'test_user',
        ],
        'user' => [
            'id' => '42',
            'username' => 'test_user',
            'name' => 'Test User',
            'language_code' => 'ru',
        ],
        'message_id' => 100 + (int) $update_id,
        'message_date' => 1785000000,
        'message_thread_id' => 77,
        'direct_messages_topic_id' => '9007199254740',
    ];
}

/** @return array<string, mixed> */
function telegramProcessorJob(array $payload, int $attempt_count = 1): array
{
    return [
        'update_id' => (string) $payload['update_id'],
        'lease_token' => str_repeat('a', 32),
        'attempt_count' => $attempt_count,
        'payload' => $payload,
    ];
}

function telegramProcessorOk(mixed $result): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'ok' => true,
        'result' => $result,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

/** @param list<mixed> $responses */
function telegramProcessorApi(TelegramBotConfig $Config, array $responses, array &$history): TelegramBotApiClient
{
    $Handler = HandlerStack::create(new MockHandler($responses));
    $Handler->push(Middleware::history($history));

    return new TelegramBotApiClient($Config, new Client([
        'handler' => $Handler,
        'base_uri' => 'https://api.telegram.org/',
    ]));
}

/** @return array<string, mixed> */
function telegramProcessorRequestPayload(array $history, int $index): array
{
    $Request = $history[$index]['request'] ?? null;
    if (!$Request instanceof RequestInterface) {
        throw new RuntimeException('Expected a recorded Telegram HTTP request.');
    }
    $payload = json_decode((string) $Request->getBody(), true, 512, JSON_THROW_ON_ERROR);

    return is_array($payload) ? $payload : [];
}

/** @return list<string> */
function telegramProcessorRequestMethods(array $history): array
{
    return array_map(
        static function (array $transaction): string {
            $Request = $transaction['request'] ?? null;
            if (!$Request instanceof RequestInterface) {
                throw new RuntimeException('Expected a recorded Telegram HTTP request.');
            }

            return basename($Request->getUri()->getPath());
        },
        $history
    );
}

/** @return list<string> */
function telegramProcessorReactionEmojis(array $history): array
{
    $emojis = [];
    foreach ($history as $index => $transaction) {
        $Request = $transaction['request'] ?? null;
        if (!$Request instanceof RequestInterface
            || basename($Request->getUri()->getPath()) !== 'setMessageReaction'
        ) {
            continue;
        }
        $payload = telegramProcessorRequestPayload($history, $index);
        $emoji = $payload['reaction'][0]['emoji'] ?? null;
        if (is_string($emoji)) {
            $emojis[] = $emoji;
        }
    }

    return $emojis;
}

$known_account = 'GDUMK6YJZ6ZC72CAMVHLUHLIFTNSLD7WFWO75Q3T2EOEW75XWH4PNSOZ';
$unknown_account = 'GBAFR44OY2EXAVZ6TL3ENEWEATKLHCKVGKQAZLZFWKUDYXVCLKOMZEIU';
$Config = new TelegramBotConfig([
    'TG_BOT_KEY' => '123456:TEST_token',
    'TG_BOT_USERNAME' => 'BSN_robot',
    'TG_WEBHOOK_SECRET' => 'test_secret',
    'ADMINS_TG' => '42',
]);
$Catalog = new KnownTagsCatalog(new RequestLocale(), dirname(__DIR__) . '/known_tags');
$BSN = new BSN(new TelegramProcessorAccountsManager(), new TelegramProcessorDocumentsManager());
$BSN->loadKnownTags($Catalog->list());
$BSN->loadFromJson([
    'createDate' => '2026-07-25T00:00:00+00:00',
    'accounts' => [
        $known_account => ['profile' => ['Name' => ['Known account']]],
    ],
]);
$Reports = new AccountReportBuilder($BSN, $Catalog);
$Translator = new Translator('ru');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.ru.yaml', 'ru');
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');
$Renderer = new AccountRichMessageRenderer($Translator);

$account_jobs = [
    telegramProcessorJob(telegramProcessorPayload(
        '1',
        TelegramUpdateParser::TYPE_ACCOUNT_INFO,
        TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
        $known_account
    )),
    telegramProcessorJob(telegramProcessorPayload(
        '2',
        TelegramUpdateParser::TYPE_ACCOUNT_INFO,
        TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
        $unknown_account
    )),
];
$AccountUpdates = new TelegramProcessorUpdateStore($account_jobs);
$AccountUsage = new TelegramProcessorUsageStore();
$AccountSubscriptions = new TelegramProcessorSubscriptionStore();
$account_history = [];
$AccountApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    telegramProcessorOk(['message_id' => 501]),
    telegramProcessorOk(true),
    telegramProcessorOk(true),
    telegramProcessorOk(['message_id' => 502]),
    telegramProcessorOk(true),
], $account_history);
$AccountProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $AccountApi,
    $Config,
    $AccountUpdates,
    $AccountUsage,
    $AccountSubscriptions
);
assertTelegramProcessor(true, $AccountProcessor->processNext(), 'A known account job must be claimed.');
assertTelegramProcessor(true, $AccountProcessor->processNext(), 'An unknown account job must be claimed.');
assertTelegramProcessor(false, $AccountProcessor->processNext(), 'An empty queue must be reported.');
assertTelegramProcessor(2, count($AccountUpdates->completed), 'Both confirmed article sends must complete.');
assertTelegramProcessor(2, count($AccountUsage->events), 'Only confirmed account responses must enter usage.');
assertTelegramProcessor(true, $AccountUsage->events[0]['known'], 'A snapshot account must be recorded as known.');
assertTelegramProcessor(false, $AccountUsage->events[1]['known'], 'A valid unknown account must still be recorded.');
assertTelegramProcessor('article', $AccountUsage->events[1]['outcome'], 'Unknown accounts still receive articles.');
assertTelegramProcessor(
    [
        'setMessageReaction',
        'sendRichMessage',
        'setMessageReaction',
        'setMessageReaction',
        'sendRichMessage',
        'setMessageReaction',
    ],
    telegramProcessorRequestMethods($account_history),
    'Each successful lookup must send eyes, then the article, then success.'
);
assertTelegramProcessor(
    [
        TelegramBotApiClient::REACTION_PROCESSING,
        TelegramBotApiClient::REACTION_SUCCESS,
        TelegramBotApiClient::REACTION_PROCESSING,
        TelegramBotApiClient::REACTION_SUCCESS,
    ],
    telegramProcessorReactionEmojis($account_history),
    'Successful lookup reactions must transition from eyes to success.'
);
$first_article_payload = telegramProcessorRequestPayload($account_history, 1);
assertTelegramProcessor(101, $first_article_payload['reply_parameters']['message_id'], 'The response must reply to the request.');
assertTelegramProcessor(true, $first_article_payload['reply_parameters']['allow_sending_without_reply'], 'Reply delivery must survive a deleted source message.');
assertTelegramProcessor(77, $first_article_payload['message_thread_id'], 'The originating thread must be preserved.');
assertTelegramProcessor(9007199254740, $first_article_payload['direct_messages_topic_id'], 'A stored topic string must become an API integer.');

$AdminUpdates = new TelegramProcessorUpdateStore([
    telegramProcessorJob(telegramProcessorPayload(
        '3',
        TelegramUpdateParser::TYPE_ADMIN_COMMAND,
        TelegramUpdateParser::COMMAND_DAILY_REPORT_ON
    )),
]);
$AdminUsage = new TelegramProcessorUsageStore();
$AdminSubscriptions = new TelegramProcessorSubscriptionStore();
$admin_history = [];
$AdminApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    telegramProcessorOk(['message_id' => 503]),
    telegramProcessorOk(true),
], $admin_history);
$AdminProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $AdminApi,
    $Config,
    $AdminUpdates,
    $AdminUsage,
    $AdminSubscriptions
);
$AdminProcessor->processNext();
assertTelegramProcessor(true, $AdminSubscriptions->enabled, 'A private configured admin may enable reports.');
assertTelegramProcessor(1, count($AdminUpdates->completed), 'A confirmed admin command must complete.');
assertTelegramProcessor([], $AdminUsage->events, 'Admin commands must not enter account usage statistics.');
assertTelegramProcessor(
    ['setMessageReaction', 'sendMessage', 'setMessageReaction'],
    telegramProcessorRequestMethods($admin_history),
    'A successful admin command must transition from eyes to success after its reply.'
);
assertTelegramProcessor(
    [TelegramBotApiClient::REACTION_PROCESSING, TelegramBotApiClient::REACTION_SUCCESS],
    telegramProcessorReactionEmojis($admin_history),
    'Successful admin reactions must have the expected emojis.'
);

$ValidationUpdates = new TelegramProcessorUpdateStore([
    telegramProcessorJob(telegramProcessorPayload(
        '7',
        TelegramUpdateParser::TYPE_VALIDATION_ERROR,
        TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
        validation_error: 'missing_account_id'
    )),
]);
$validation_history = [];
$ValidationApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    telegramProcessorOk(['message_id' => 504]),
], $validation_history);
$ValidationProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $ValidationApi,
    $Config,
    $ValidationUpdates,
    new TelegramProcessorUsageStore(),
    new TelegramProcessorSubscriptionStore()
);
$ValidationProcessor->processNext();
assertTelegramProcessor(1, count($ValidationUpdates->completed), 'A delivered validation reply must complete.');
assertTelegramProcessor(
    ['setMessageReaction', 'sendMessage'],
    telegramProcessorRequestMethods($validation_history),
    'A validation reply must keep eyes and must not send a success reaction.'
);
assertTelegramProcessor(
    [TelegramBotApiClient::REACTION_PROCESSING],
    telegramProcessorReactionEmojis($validation_history),
    'A validation reply must retain only the processing reaction.'
);

$ErrorUpdates = new TelegramProcessorUpdateStore([
    telegramProcessorJob(telegramProcessorPayload(
        '8',
        TelegramUpdateParser::TYPE_ACCOUNT_INFO,
        TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
        $known_account
    )),
]);
$ErrorUsage = new TelegramProcessorUsageStore();
$error_history = [];
$ErrorApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request: rich message rejected',
    ], JSON_THROW_ON_ERROR)),
    telegramProcessorOk(['message_id' => 505]),
], $error_history);
$ErrorProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $ErrorApi,
    $Config,
    $ErrorUpdates,
    $ErrorUsage,
    new TelegramProcessorSubscriptionStore()
);
$ErrorProcessor->processNext();
assertTelegramProcessor(1, count($ErrorUpdates->completed), 'A delivered account error fallback must complete.');
assertTelegramProcessor('error', $ErrorUsage->events[0]['outcome'] ?? null, 'A delivered fallback must be recorded as an error outcome.');
assertTelegramProcessor(
    ['setMessageReaction', 'sendRichMessage', 'sendMessage'],
    telegramProcessorRequestMethods($error_history),
    'An account error fallback must not send a success reaction.'
);
assertTelegramProcessor(
    [TelegramBotApiClient::REACTION_PROCESSING],
    telegramProcessorReactionEmojis($error_history),
    'An account error fallback must retain only the processing reaction.'
);

$ReconcilePayload = telegramProcessorPayload(
    '9',
    TelegramUpdateParser::TYPE_ACCOUNT_INFO,
    TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
    $known_account
);
$ReconcileUpdates = new TelegramProcessorUpdateStore([
    telegramProcessorJob($ReconcilePayload),
]);
$ReconcileUsage = new TelegramProcessorUsageStore(1);
$reconcile_history = [];
$ReconcileApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    telegramProcessorOk(['message_id' => 506]),
    telegramProcessorOk(true),
    telegramProcessorOk(true),
], $reconcile_history);
$ReconcileProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $ReconcileApi,
    $Config,
    $ReconcileUpdates,
    $ReconcileUsage,
    new TelegramProcessorSubscriptionStore()
);
assertTelegramProcessor(true, $ReconcileProcessor->processNext(), 'The initial lookup delivery must be processed.');
assertTelegramProcessor(1, count($ReconcileUpdates->usage_pending), 'Confirmed delivery must persist pending usage before recording it.');
assertTelegramProcessor(1, count($ReconcileUpdates->retried), 'A failed usage write must schedule reconciliation.');
assertTelegramProcessor(60, $ReconcileUpdates->retried[0]['delay_seconds'] ?? null, 'Usage reconciliation must use its bounded retry delay.');
assertTelegramProcessor([], $ReconcileUpdates->completed, 'A job with unrecorded usage must not complete.');
assertTelegramProcessor([], $ReconcileUsage->events, 'A failed usage write must not create an event.');
assertTelegramProcessor(
    ['setMessageReaction', 'sendRichMessage'],
    telegramProcessorRequestMethods($reconcile_history),
    'The initial pass must stop after confirmed delivery when usage recording fails.'
);

$pending = $ReconcileUpdates->usage_pending[0];
$ReconcileUpdates->jobs[] = telegramProcessorJob($ReconcilePayload, 2) + [
    'phase' => TelegramUpdateStore::PHASE_RECORD_USAGE,
    'pending_usage' => $pending['usage'],
    'pending_effect' => $pending['effect'],
    'pending_success_reaction' => $pending['success_reaction'],
];
assertTelegramProcessor(true, $ReconcileProcessor->processNext(), 'The persisted usage phase must be resumable.');
assertTelegramProcessor(false, $ReconcileProcessor->processNext(), 'The reconciled queue must be empty.');
assertTelegramProcessor(2, $ReconcileUsage->attempts, 'Usage recording must be retried exactly once.');
assertTelegramProcessor(1, count($ReconcileUsage->events), 'A successful reconciliation must write one usage event.');
assertTelegramProcessor(1, count($ReconcileUpdates->completed), 'Successful reconciliation must complete the original update.');
assertTelegramProcessor($pending['effect'], $ReconcileUpdates->completed[0]['effect'], 'Reconciliation must complete with the persisted response effect.');
assertTelegramProcessor(
    [
        'setMessageReaction',
        'sendRichMessage',
        'setMessageReaction',
        'setMessageReaction',
    ],
    telegramProcessorRequestMethods($reconcile_history),
    'Usage reconciliation must not send the article or fallback a second time.'
);
assertTelegramProcessor(
    [
        TelegramBotApiClient::REACTION_PROCESSING,
        TelegramBotApiClient::REACTION_PROCESSING,
        TelegramBotApiClient::REACTION_SUCCESS,
    ],
    telegramProcessorReactionEmojis($reconcile_history),
    'Reconciliation may repeat eyes and must finish with success after usage is stored.'
);

$RateUpdates = new TelegramProcessorUpdateStore([
    telegramProcessorJob(telegramProcessorPayload(
        '4',
        TelegramUpdateParser::TYPE_VALIDATION_ERROR,
        TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
        validation_error: 'missing_account_id'
    )),
]);
$rate_history = [];
$RateApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    new Response(429, ['Content-Type' => 'application/json'], json_encode([
        'ok' => false,
        'error_code' => 429,
        'description' => 'Too Many Requests',
        'parameters' => ['retry_after' => 7200],
    ], JSON_THROW_ON_ERROR)),
], $rate_history);
$RateProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $RateApi,
    $Config,
    $RateUpdates,
    new TelegramProcessorUsageStore(),
    new TelegramProcessorSubscriptionStore()
);
$RateProcessor->processNext();
assertTelegramProcessor(7200, $RateUpdates->retried[0]['delay_seconds'] ?? null, 'A long retry_after must not be capped.');
assertTelegramProcessor([], $RateUpdates->completed, 'A rejected response must not complete.');
assertTelegramProcessor(
    [TelegramBotApiClient::REACTION_PROCESSING],
    telegramProcessorReactionEmojis($rate_history),
    'A retryable validation failure must not send a success reaction.'
);

$UncertainUpdates = new TelegramProcessorUpdateStore([
    telegramProcessorJob(telegramProcessorPayload(
        '5',
        TelegramUpdateParser::TYPE_ACCOUNT_INFO,
        TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
        $known_account
    )),
]);
$UncertainUsage = new TelegramProcessorUsageStore();
$uncertain_history = [];
$UncertainApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    new ConnectException('Connection failed', new Request('POST', 'https://api.telegram.org/')),
], $uncertain_history);
$UncertainProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $UncertainApi,
    $Config,
    $UncertainUpdates,
    $UncertainUsage,
    new TelegramProcessorSubscriptionStore()
);
$UncertainProcessor->processNext();
assertTelegramProcessor(1, count($UncertainUpdates->uncertain), 'A transport uncertainty must be terminal.');
assertTelegramProcessor([], $UncertainUpdates->retried, 'A possibly delivered article must not be retried.');
assertTelegramProcessor([], $UncertainUsage->events, 'Unconfirmed delivery must not enter usage statistics.');
assertTelegramProcessor(
    [TelegramBotApiClient::REACTION_PROCESSING],
    telegramProcessorReactionEmojis($uncertain_history),
    'An uncertain account response must not send a success reaction.'
);

$TerminalUpdates = new TelegramProcessorUpdateStore([
    telegramProcessorJob(telegramProcessorPayload(
        '6',
        TelegramUpdateParser::TYPE_VALIDATION_ERROR,
        TelegramUpdateParser::COMMAND_ACCOUNT_INFO,
        validation_error: 'invalid_account_id'
    )),
]);
$terminal_history = [];
$TerminalApi = telegramProcessorApi($Config, [
    telegramProcessorOk(true),
    new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request',
    ], JSON_THROW_ON_ERROR)),
], $terminal_history);
$TerminalProcessor = new TelegramUpdateProcessor(
    $BSN,
    $Reports,
    $Renderer,
    $TerminalApi,
    $Config,
    $TerminalUpdates,
    new TelegramProcessorUsageStore(),
    new TelegramProcessorSubscriptionStore()
);
$TerminalProcessor->processNext();
assertTelegramProcessor(1, count($TerminalUpdates->failed), 'A terminal Bot API rejection must fail the job.');
assertTelegramProcessor([], $TerminalUpdates->retried, 'A terminal 4xx must not retry.');
assertTelegramProcessor(
    [TelegramBotApiClient::REACTION_PROCESSING],
    telegramProcessorReactionEmojis($terminal_history),
    'A terminal validation failure must not send a success reaction.'
);

fwrite(STDOUT, "Telegram update processor regression tests passed.\n");
