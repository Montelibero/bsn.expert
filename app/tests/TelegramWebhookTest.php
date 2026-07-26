<?php

declare(strict_types=1);

use MongoDB\Driver\Manager;
use Montelibero\BSN\Telegram\TelegramBotConfig;
use Montelibero\BSN\Telegram\TelegramUpdateParser;
use Montelibero\BSN\Telegram\TelegramUpdateStore;
use Montelibero\BSN\Telegram\TelegramWebhookAccess;
use Montelibero\BSN\Telegram\TelegramWebhookController;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

final class TelegramWebhookTestStore extends TelegramUpdateStore
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];
    public string $result = self::ENQUEUE_ACCEPTED;
    public ?Throwable $failure = null;

    public function __construct()
    {
    }

    public function enqueue(array $payload): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->payloads[] = $payload;

        return $this->result;
    }
}

function assertTelegramWebhook(mixed $expected, mixed $actual, string $message): void
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

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function telegramWebhookUpdate(string $text, array $overrides = []): array
{
    $update = [
        'update_id' => 123456,
        'message' => [
            'message_id' => 91,
            'message_thread_id' => 77,
            'direct_messages_topic' => ['topic_id' => 9007199254740],
            'date' => 1785000000,
            'from' => [
                'id' => 42,
                'is_bot' => false,
                'first_name' => "Ada\n",
                'last_name' => 'Lovelace',
                'username' => 'ada_lovelace',
                'language_code' => 'RU',
            ],
            'chat' => [
                'id' => 42,
                'type' => 'private',
                'title' => null,
                'username' => 'ada_lovelace',
            ],
            'text' => $text,
        ],
    ];

    return array_replace_recursive($update, $overrides);
}

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function telegramWebhookRequest(
    TelegramWebhookController $Controller,
    string $body,
    string $method = 'POST',
    string $content_type = 'application/json',
    ?string $secret = 'test_webhook_secret',
    ?string $content_length = null,
): array {
    $_SERVER = [
        'REQUEST_METHOD' => $method,
        'CONTENT_TYPE' => $content_type,
    ];
    if ($secret !== null) {
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;
    }
    if ($content_length !== null) {
        $_SERVER['CONTENT_LENGTH'] = $content_length;
    }

    http_response_code(200);
    $json = $Controller->receive($body);
    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Telegram webhook response must be a JSON object.');
    }

    return [
        'status' => http_response_code(),
        'body' => $decoded,
    ];
}

$Config = new TelegramBotConfig([
    'TG_BOT_KEY' => '123456:TEST_token',
    'TG_BOT_USERNAME' => '@BSN_robot',
    'TG_WEBHOOK_SECRET' => 'test_webhook_secret',
    'ADMINS_TG' => '42',
]);
$Parser = new TelegramUpdateParser($Config);

$account_id = 'GDUMK6YJZ6ZC72CAMVHLUHLIFTNSLD7WFWO75Q3T2EOEW75XWH4PNSOZ';
$second_account_id = 'GBAFR44OY2EXAVZ6TL3ENEWEATKLHCKVGKQAZLZFWKUDYXVCLKOMZEIU';
$invalid_checksum = substr($account_id, 0, -1) . (str_ends_with($account_id, 'A') ? 'B' : 'A');

$private = $Parser->parse(telegramWebhookUpdate(strtolower($account_id)));
assertTelegramWebhook(TelegramUpdateParser::TYPE_ACCOUNT_INFO, $private['type'] ?? null, 'Private address must parse.');
assertTelegramWebhook(TelegramUpdateParser::COMMAND_ACCOUNT, $private['command'] ?? null, 'Private address must use the canonical account command.');
assertTelegramWebhook($account_id, $private['account_id'] ?? null, 'Private address must normalize to uppercase.');
assertTelegramWebhook('123456', $private['update_id'] ?? null, 'Update ID must be stored as a string.');
assertTelegramWebhook('42', $private['chat']['id'] ?? null, 'Chat ID must be stored as a string.');
assertTelegramWebhook('42', $private['user']['id'] ?? null, 'User ID must be stored as a string.');
assertTelegramWebhook('Ada Lovelace', $private['user']['name'] ?? null, 'User name must be normalized.');
assertTelegramWebhook('ru', $private['user']['language_code'] ?? null, 'Language code must be normalized.');
assertTelegramWebhook(77, $private['message_thread_id'] ?? null, 'Message thread must be preserved.');
assertTelegramWebhook('9007199254740', $private['direct_messages_topic_id'] ?? null, 'Direct topic must be preserved.');
assertTelegramWebhook(null, $Parser->parse(telegramWebhookUpdate($invalid_checksum)), 'Invalid plain address must be ignored.');
assertTelegramWebhook(null, $Parser->parse(telegramWebhookUpdate('ordinary text')), 'Unrelated private text must be ignored.');

$deep_link = $Parser->parse(telegramWebhookUpdate('/start a_' . strtolower($account_id)));
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_ACCOUNT_INFO,
    $deep_link['type'] ?? null,
    'Private account deep link must parse as an account lookup.'
);
assertTelegramWebhook(
    TelegramUpdateParser::COMMAND_ACCOUNT,
    $deep_link['command'] ?? null,
    'Private account deep link must use the canonical account command.'
);
assertTelegramWebhook(
    $account_id,
    $deep_link['account_id'] ?? null,
    'Private account deep link must normalize the account ID.'
);
$suffixed_deep_link = $Parser->parse(telegramWebhookUpdate(
    '/start@BSN_robot a_' . $account_id
));
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_ACCOUNT_INFO,
    $suffixed_deep_link['type'] ?? null,
    'A deep link command suffixed with this bot username must parse.'
);
assertTelegramWebhook(
    null,
    $Parser->parse(telegramWebhookUpdate('/start@Other_robot a_' . $account_id)),
    'A deep link command suffixed with another bot username must be ignored.'
);
$start_help = $Parser->parse(telegramWebhookUpdate('/start'));
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $start_help['type'] ?? null,
    'Start without a payload must use the existing validation queue type.'
);
assertTelegramWebhook(
    TelegramUpdateParser::COMMAND_ACCOUNT,
    $start_help['command'] ?? null,
    'Start help must keep a command understood by older workers.'
);
assertTelegramWebhook(
    'missing_account_id',
    $start_help['validation_error'] ?? null,
    'Start help must keep a validation code understood by older workers.'
);
assertTelegramWebhook(
    'help_requested',
    $start_help['help_context'] ?? null,
    'Start without a payload must request help.'
);
$unsupported_start = $Parser->parse(telegramWebhookUpdate('/start d_' . $account_id));
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $unsupported_start['type'] ?? null,
    'Unsupported deep-link payload must use the existing validation queue type.'
);
assertTelegramWebhook(
    TelegramUpdateParser::COMMAND_ACCOUNT,
    $unsupported_start['command'] ?? null,
    'Unsupported deep-link payload must keep a command understood by older workers.'
);
assertTelegramWebhook(
    'invalid_account_id',
    $unsupported_start['validation_error'] ?? null,
    'Unsupported deep-link payload must keep a validation code understood by older workers.'
);
assertTelegramWebhook(
    'invalid_start_payload',
    $unsupported_start['help_context'] ?? null,
    'Unsupported deep-link payload must use the start payload help context.'
);
$invalid_start = $Parser->parse(telegramWebhookUpdate('/start a_' . $invalid_checksum));
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $invalid_start['type'] ?? null,
    'Invalid account deep-link payload must use the existing validation queue type.'
);
assertTelegramWebhook(
    'invalid_account_id',
    $invalid_start['validation_error'] ?? null,
    'Invalid account deep-link payload must keep a validation code understood by older workers.'
);
assertTelegramWebhook(
    'invalid_start_payload',
    $invalid_start['help_context'] ?? null,
    'Invalid account deep-link payload must use the start payload help context.'
);

$private_help = $Parser->parse(telegramWebhookUpdate('/help explain this'));
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $private_help['type'] ?? null,
    'Help must use the existing validation queue type.'
);
assertTelegramWebhook(
    TelegramUpdateParser::COMMAND_ACCOUNT,
    $private_help['command'] ?? null,
    'Help must keep a command understood by older workers.'
);
assertTelegramWebhook(
    'missing_account_id',
    $private_help['validation_error'] ?? null,
    'Help must keep a validation code understood by older workers.'
);
assertTelegramWebhook(
    'help_requested',
    $private_help['help_context'] ?? null,
    'Help arguments must be ignored and help must be requested.'
);
assertTelegramWebhook(
    null,
    $Parser->parse(telegramWebhookUpdate('/help@Other_robot')),
    'Help suffixed with another bot username must be ignored.'
);

$group_update = telegramWebhookUpdate('/account@BSN_robot ' . strtolower($account_id), [
    'message' => [
        'chat' => [
            'id' => -100123,
            'type' => 'supergroup',
            'title' => 'BSN group',
            'username' => 'bsn_group',
        ],
    ],
]);
$group = $Parser->parse($group_update);
assertTelegramWebhook(TelegramUpdateParser::TYPE_ACCOUNT_INFO, $group['type'] ?? null, 'Own suffixed group command must parse.');
assertTelegramWebhook(TelegramUpdateParser::COMMAND_ACCOUNT, $group['command'] ?? null, 'Primary account command must stay canonical.');
assertTelegramWebhook('-100123', $group['chat']['id'] ?? null, 'Negative group ID must be preserved.');
assertTelegramWebhook('BSN group', $group['chat']['title'] ?? null, 'Group title must be preserved.');

$group_deep_link = $group_update;
$group_deep_link['message']['text'] = '/start a_' . $account_id;
assertTelegramWebhook(null, $Parser->parse($group_deep_link), 'Account deep links must be private-only.');

$group_start = $group_update;
$group_start['message']['text'] = '/start';
assertTelegramWebhook(null, $Parser->parse($group_start), 'Start without a payload must be ignored outside private chats.');

$group_help = $group_update;
$group_help['message']['text'] = '/help@BSN_robot ignored argument';
$parsed_group_help = $Parser->parse($group_help);
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $parsed_group_help['type'] ?? null,
    'Help must be accepted in groups and supergroups.'
);
assertTelegramWebhook(
    TelegramUpdateParser::COMMAND_ACCOUNT,
    $parsed_group_help['command'] ?? null,
    'Group help must keep a command understood by older workers.'
);
assertTelegramWebhook(
    'missing_account_id',
    $parsed_group_help['validation_error'] ?? null,
    'Group help must keep a validation code understood by older workers.'
);
assertTelegramWebhook(
    'help_requested',
    $parsed_group_help['help_context'] ?? null,
    'Group help must request help even when arguments are present.'
);

$basic_group_help = $group_help;
$basic_group_help['message']['chat']['type'] = 'group';
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $Parser->parse($basic_group_help)['type'] ?? null,
    'Help must also be accepted in basic groups.'
);

$group_alias = $group_update;
$group_alias['message']['text'] = '/account_info ' . $account_id;
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_ACCOUNT_INFO,
    $Parser->parse($group_alias)['type'] ?? null,
    'Legacy account_info alias must parse.'
);
assertTelegramWebhook(
    TelegramUpdateParser::COMMAND_ACCOUNT,
    $Parser->parse($group_alias)['command'] ?? null,
    'Legacy account_info alias must normalize to the canonical command.'
);

$foreign_suffix = $group_update;
$foreign_suffix['message']['text'] = '/account@Other_robot ' . $account_id;
assertTelegramWebhook(null, $Parser->parse($foreign_suffix), 'Foreign bot suffix must be ignored.');

$account_prompt = $group_update;
$account_prompt['message']['text'] = '/account@BSN_robot';
$prompt = $Parser->parse($account_prompt);
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $prompt['type'] ?? null,
    'Account prompt must retain the backward-compatible queue type.'
);
assertTelegramWebhook(TelegramUpdateParser::COMMAND_ACCOUNT, $prompt['command'] ?? null, 'Account prompt must use the canonical command.');
assertTelegramWebhook(
    'missing_account_id',
    $prompt['validation_error'] ?? null,
    'Account command without an argument must request an account.'
);

$account_info_prompt = $group_update;
$account_info_prompt['message']['text'] = '/account_info';
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_VALIDATION_ERROR,
    $Parser->parse($account_info_prompt)['type'] ?? null,
    'Legacy account_info alias must retain the backward-compatible queue type.'
);
assertTelegramWebhook(
    'missing_account_id',
    $Parser->parse($account_info_prompt)['validation_error'] ?? null,
    'Legacy account_info alias without an argument must request an account.'
);
assertTelegramWebhook(
    TelegramUpdateParser::COMMAND_ACCOUNT,
    $Parser->parse($account_info_prompt)['command'] ?? null,
    'Legacy account_info alias prompt must normalize to the canonical command.'
);

$extra_account = $group_update;
$extra_account['message']['text'] = '/account_info ' . $account_id . ' ' . $second_account_id;
$validation = $Parser->parse($extra_account);
assertTelegramWebhook(TelegramUpdateParser::TYPE_VALIDATION_ERROR, $validation['type'] ?? null, 'Extra argument must be typed.');
assertTelegramWebhook('invalid_account_id', $validation['validation_error'] ?? null, 'Extra argument must not be accepted.');

$group_plain_address = $group_update;
$group_plain_address['message']['text'] = $account_id;
assertTelegramWebhook(null, $Parser->parse($group_plain_address), 'Bare group address without a prompt reply must be ignored.');

$group_prompt_reply = $group_plain_address;
$group_prompt_reply['message']['reply_to_message'] = [
    'message_id' => 90,
    'date' => 1784999999,
    'from' => [
        'id' => 123456,
        'is_bot' => true,
        'first_name' => 'BSN Robot',
        'username' => 'BSN_robot',
    ],
    'chat' => $group_prompt_reply['message']['chat'],
    'text' => TelegramUpdateParser::ACCOUNT_PROMPT_TEXT,
];
$prompt_reply = $Parser->parse($group_prompt_reply);
assertTelegramWebhook(TelegramUpdateParser::TYPE_ACCOUNT_INFO, $prompt_reply['type'] ?? null, 'Group address replying to the exact bot prompt must parse.');
assertTelegramWebhook($account_id, $prompt_reply['account_id'] ?? null, 'Prompt reply account must be preserved.');

$english_prompt_reply = $group_prompt_reply;
$english_prompt_reply['message']['reply_to_message']['text'] = TelegramUpdateParser::ACCOUNT_PROMPT_TEXT_EN;
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_ACCOUNT_INFO,
    $Parser->parse($english_prompt_reply)['type'] ?? null,
    'Group address replying to the exact English bot prompt must parse.'
);

$private_prompt_reply = telegramWebhookUpdate(strtolower($account_id), [
    'message' => [
        'reply_to_message' => $group_prompt_reply['message']['reply_to_message'],
    ],
]);
assertTelegramWebhook(
    TelegramUpdateParser::TYPE_ACCOUNT_INFO,
    $Parser->parse($private_prompt_reply)['type'] ?? null,
    'Private address replying to the exact bot prompt must parse.'
);

$invalid_prompt_reply = $group_prompt_reply;
$invalid_prompt_reply['message']['text'] = 'not a Stellar address';
$validation = $Parser->parse($invalid_prompt_reply);
assertTelegramWebhook(TelegramUpdateParser::TYPE_VALIDATION_ERROR, $validation['type'] ?? null, 'Invalid prompt reply must be typed.');
assertTelegramWebhook('invalid_account_id', $validation['validation_error'] ?? null, 'Invalid prompt reply must use the account validation code.');

$human_prompt = $group_prompt_reply;
$human_prompt['message']['reply_to_message']['from']['is_bot'] = false;
assertTelegramWebhook(null, $Parser->parse($human_prompt), 'A human message copying the prompt must not authorize a bare group address.');

$foreign_bot_prompt = $group_prompt_reply;
$foreign_bot_prompt['message']['reply_to_message']['from']['username'] = 'Other_robot';
assertTelegramWebhook(null, $Parser->parse($foreign_bot_prompt), 'A foreign bot prompt must not authorize a bare group address.');

$changed_prompt = $group_prompt_reply;
$changed_prompt['message']['reply_to_message']['text'] = TelegramUpdateParser::ACCOUNT_PROMPT_TEXT . ' ';
assertTelegramWebhook(null, $Parser->parse($changed_prompt), 'Prompt text must match exactly.');

$daily = $Parser->parse(telegramWebhookUpdate('/daily_report_on@BSN_robot'));
assertTelegramWebhook(TelegramUpdateParser::TYPE_ADMIN_COMMAND, $daily['type'] ?? null, 'Private daily command must parse.');
assertTelegramWebhook(TelegramUpdateParser::COMMAND_DAILY_REPORT_ON, $daily['command'] ?? null, 'Daily command type must be preserved.');

$daily_with_argument = $Parser->parse(telegramWebhookUpdate('/daily_report_off now'));
assertTelegramWebhook(TelegramUpdateParser::TYPE_VALIDATION_ERROR, $daily_with_argument['type'] ?? null, 'Admin command arguments must be rejected.');
assertTelegramWebhook('unexpected_argument', $daily_with_argument['validation_error'] ?? null, 'Admin validation code must be stable.');

$group_daily = $group_update;
$group_daily['message']['text'] = '/daily_report_on';
assertTelegramWebhook(null, $Parser->parse($group_daily), 'Daily command must be private-only.');

$bot_update = telegramWebhookUpdate('/account ' . $account_id, ['message' => ['from' => ['is_bot' => true]]]);
assertTelegramWebhook(null, $Parser->parse($bot_update), 'Messages from bots must be ignored.');

$Manager = new Manager('mongodb://127.0.0.1:1', ['serverSelectionTimeoutMS' => 1]);
$DurableStore = new TelegramUpdateStore($Manager, 'test_database');
$WriteConcernProperty = new ReflectionProperty($DurableStore, 'WriteConcern');
$WriteConcern = $WriteConcernProperty->getValue($DurableStore);
assertTelegramWebhook(1, $WriteConcern->getW(), 'Inbox writes must require one acknowledgement.');
assertTelegramWebhook(true, $WriteConcern->getJournal(), 'Inbox writes must require journal acknowledgement.');

$FakeStore = new TelegramWebhookTestStore();
$Controller = new TelegramWebhookController(
    new TelegramWebhookAccess($Config),
    $Parser,
    $FakeStore
);
$accepted_update = telegramWebhookUpdate('/account ' . $account_id);
$accepted_json = json_encode($accepted_update, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

$response = telegramWebhookRequest($Controller, $accepted_json, method: 'GET');
assertTelegramWebhook(405, $response['status'], 'Non-POST webhook must be rejected.');

$response = telegramWebhookRequest($Controller, $accepted_json, content_type: 'text/plain');
assertTelegramWebhook(415, $response['status'], 'Non-JSON webhook must be rejected.');

$response = telegramWebhookRequest($Controller, $accepted_json, content_type: 'application/json; invalid');
assertTelegramWebhook(415, $response['status'], 'Malformed JSON content type must be rejected.');

$response = telegramWebhookRequest($Controller, $accepted_json, secret: 'wrong_secret');
assertTelegramWebhook(403, $response['status'], 'Wrong webhook secret must be rejected.');

$response = telegramWebhookRequest($Controller, $accepted_json, content_length: (string) (TelegramWebhookController::MAX_BODY_BYTES + 1));
assertTelegramWebhook(413, $response['status'], 'Oversized declared body must be rejected.');

$response = telegramWebhookRequest($Controller, str_repeat('x', TelegramWebhookController::MAX_BODY_BYTES + 1));
assertTelegramWebhook(413, $response['status'], 'Oversized actual body must be rejected.');

$response = telegramWebhookRequest($Controller, '{');
assertTelegramWebhook(400, $response['status'], 'Malformed JSON must be rejected.');

$irrelevant_json = json_encode(telegramWebhookUpdate('ordinary text'), JSON_THROW_ON_ERROR);
$response = telegramWebhookRequest($Controller, $irrelevant_json);
assertTelegramWebhook(200, $response['status'], 'Irrelevant update must be acknowledged.');
assertTelegramWebhook('ignored', $response['body']['status'] ?? null, 'Irrelevant update response must be typed.');
assertTelegramWebhook([], $FakeStore->payloads, 'Irrelevant update must not reach the queue.');

$response = telegramWebhookRequest($Controller, $accepted_json, content_type: 'application/json; charset=utf-8');
assertTelegramWebhook(200, $response['status'], 'Accepted update must be acknowledged.');
assertTelegramWebhook('setMessageReaction', $response['body']['method'] ?? null, 'Accepted update must request a reaction.');
assertTelegramWebhook('👀', $response['body']['reaction'][0]['emoji'] ?? null, 'Accepted reaction must mark processing.');
assertTelegramWebhook(1, count($FakeStore->payloads), 'Accepted update must be enqueued once.');
$stored_payload = $FakeStore->payloads[0];
assertTelegramWebhook(false, array_key_exists('text', $stored_payload), 'Raw message text must not be stored.');
assertTelegramWebhook(false, array_key_exists('message', $stored_payload), 'Raw Telegram message must not be stored.');

$FakeStore->result = TelegramUpdateStore::ENQUEUE_DUPLICATE;
$response = telegramWebhookRequest($Controller, $accepted_json);
assertTelegramWebhook(200, $response['status'], 'Duplicate update must be acknowledged.');
assertTelegramWebhook('duplicate', $response['body']['status'] ?? null, 'Duplicate response must be typed.');

$FakeStore->failure = new RuntimeException('must not leak');
$response = telegramWebhookRequest($Controller, $accepted_json);
assertTelegramWebhook(503, $response['status'], 'Queue failure must request Telegram retry.');
assertTelegramWebhook('queue_unavailable', $response['body']['error'] ?? null, 'Queue failure response must be generic.');

fwrite(STDOUT, "Telegram webhook regression tests passed.\n");
