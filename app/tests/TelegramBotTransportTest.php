<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\Telegram\TelegramBotApiClient;
use Montelibero\BSN\Telegram\TelegramBotApiException;
use Montelibero\BSN\Telegram\TelegramBotConfig;
use Montelibero\BSN\Telegram\TelegramWebhookAccess;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertTelegramTransport(mixed $expected, mixed $actual, string $message): void
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

function telegramOkResponse(mixed $result): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'ok' => true,
        'result' => $result,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

/**
 * @param list<array<string, mixed>> $history
 * @return array<string, mixed>
 */
function telegramRequestPayload(array $history, int $index): array
{
    $payload = json_decode(
        (string) $history[$index]['request']->getBody(),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($payload)) {
        throw new RuntimeException('Telegram request payload must be an object.');
    }

    return $payload;
}

$fake_token = '123456:TEST_token';
$fake_secret = 'webhook_secret-123';
$Config = new TelegramBotConfig([
    'TG_BOT_KEY' => $fake_token,
    'TG_BOT_USERNAME' => '@BSN_robot',
    'TG_WEBHOOK_SECRET' => $fake_secret,
    'ADMINS_TG' => '3718221, 999, 3718221',
]);

assertTelegramTransport($Config, $Config->validate(), 'A valid configuration must validate fluently.');
assertTelegramTransport('BSN_robot', $Config->botUsername(), 'The leading username marker must be normalized.');
assertTelegramTransport(
    TelegramBotConfig::WEBHOOK_URL,
    $Config->webhookUrl(),
    'The webhook URL must stay fixed.'
);
assertTelegramTransport(['3718221', '999'], $Config->adminIds(), 'Admin IDs must be parsed and deduplicated.');
assertTelegramTransport(true, $Config->isAdmin(3718221), 'A configured numeric admin must be accepted.');
assertTelegramTransport(false, $Config->isAdmin('03718221'), 'Admin comparison must not use loose coercion.');

$DefaultUsernameConfig = new TelegramBotConfig([
    'TG_BOT_KEY' => $fake_token,
    'TG_WEBHOOK_SECRET' => $fake_secret,
]);
assertTelegramTransport(
    TelegramBotConfig::DEFAULT_BOT_USERNAME,
    $DefaultUsernameConfig->botUsername(),
    'The bot username must have the documented default.'
);
assertTelegramTransport([], $DefaultUsernameConfig->adminIds(), 'An empty admin list must disable admin access.');

$Access = new TelegramWebhookAccess($Config);
assertTelegramTransport(false, $Access->isAllowed(null), 'A missing webhook secret header must be rejected.');
assertTelegramTransport(false, $Access->isAllowed('wrong'), 'A wrong webhook secret header must be rejected.');
assertTelegramTransport(true, $Access->isAllowed($fake_secret), 'The exact webhook secret must be accepted.');
assertTelegramTransport(
    false,
    $Access->isAllowed(' ' . $fake_secret),
    'Webhook secret comparison must not silently trim a received value.'
);

$MissingSecretConfig = new TelegramBotConfig([
    'TG_BOT_KEY' => $fake_token,
]);
assertTelegramTransport(
    false,
    (new TelegramWebhookAccess($MissingSecretConfig))->isAllowed('anything'),
    'Webhook access must fail closed while the mandatory secret is missing.'
);
$missing_secret_rejected = false;
try {
    $MissingSecretConfig->validate();
} catch (InvalidArgumentException $Exception) {
    $missing_secret_rejected = str_contains($Exception->getMessage(), 'TG_WEBHOOK_SECRET');
}
assertTelegramTransport(true, $missing_secret_rejected, 'Configuration validation must require a webhook secret.');

$invalid_admin_rejected = false;
try {
    (new TelegramBotConfig([
        'TG_BOT_KEY' => $fake_token,
        'TG_WEBHOOK_SECRET' => $fake_secret,
        'ADMINS_TG' => '3718221,not-an-id',
    ]))->validate();
} catch (InvalidArgumentException $Exception) {
    $invalid_admin_rejected = str_contains($Exception->getMessage(), 'ADMINS_TG');
}
assertTelegramTransport(true, $invalid_admin_rejected, 'Invalid admin IDs must fail configuration validation.');

$history = [];
$Mock = new MockHandler([
    telegramOkResponse([
        'url' => TelegramBotConfig::WEBHOOK_URL,
        'pending_update_count' => 0,
    ]),
    telegramOkResponse([
        'id' => 123456,
        'is_bot' => true,
        'username' => 'BSN_robot',
    ]),
    telegramOkResponse(true),
    telegramOkResponse(['message_id' => 101]),
    telegramOkResponse(['message_id' => 102]),
    telegramOkResponse(['message_id' => 103]),
    telegramOkResponse(['message_id' => 104]),
    telegramOkResponse(true),
    telegramOkResponse(true),
    telegramOkResponse(true),
    telegramOkResponse(true),
]);
$Handler = HandlerStack::create($Mock);
$Handler->push(Middleware::history($history));
$HttpClient = new Client([
    'handler' => $Handler,
    'base_uri' => 'https://api.telegram.org/',
]);
$ApiClient = new TelegramBotApiClient($Config, $HttpClient);

$webhook_info = $ApiClient->getWebhookInfo();
assertTelegramTransport(0, $webhook_info['pending_update_count'], 'Webhook info must be returned unchanged.');
assertTelegramTransport(true, $ApiClient->setWebhook(), 'Webhook registration must return true.');

$legacy_markup = [
    'inline_keyboard' => [
        [
            ['text' => 'Open', 'url' => 'https://bsn.expert'],
        ],
    ],
];
assertTelegramTransport(
    101,
    $ApiClient->sendRichMessage('3718221', ['blocks' => []], $legacy_markup)['message_id'],
    'The legacy third reply-markup argument must stay supported.'
);
assertTelegramTransport(
    102,
    $ApiClient->sendRichMessage(-100123, ['blocks' => []], [
        'message_thread_id' => 42,
        'disable_notification' => true,
        'reply_parameters' => ['message_id' => 7],
    ])['message_id'],
    'Rich-message options must be sent through the allowlist.'
);
assertTelegramTransport(
    103,
    $ApiClient->sendRichMessage(
        '3718221',
        ['blocks' => []],
        $legacy_markup,
        ['protect_content' => true]
    )['message_id'],
    'Legacy reply markup and new options must be composable.'
);
assertTelegramTransport(
    104,
    $ApiClient->sendMessage('3718221', '<code>hello</code>', [
        'direct_messages_topic_id' => 17,
        'reply_markup' => $legacy_markup,
        'parse_mode' => 'HTML',
    ])['message_id'],
    'Plain messages must support safe HTML formatting and shared options.'
);
assertTelegramTransport(
    true,
    $ApiClient->setMessageReaction('3718221', 104, TelegramBotApiClient::REACTION_PROCESSING),
    'The processing reaction must be supported.'
);
assertTelegramTransport(
    true,
    $ApiClient->clearMessageReaction('3718221', 104),
    'The processing reaction must be removable after the response.'
);
assertTelegramTransport(true, $ApiClient->setMyCommands(), 'The public command menu must be configurable.');

assertTelegramTransport(11, count($history), 'Every successful client call must issue exactly one request.');
assertTelegramTransport(
    'https://api.telegram.org/bot' . $fake_token . '/getWebhookInfo',
    (string) $history[0]['request']->getUri(),
    'The token colon must remain part of the Telegram API path.'
);
assertTelegramTransport(
    'https://api.telegram.org/bot' . $fake_token . '/setWebhook',
    (string) $history[2]['request']->getUri(),
    'Webhook registration must use the expected Telegram method.'
);

$set_webhook_payload = telegramRequestPayload($history, 2);
assertTelegramTransport([
    'url' => 'https://bsn.expert/telegram/webhook',
    'secret_token' => $fake_secret,
    'allowed_updates' => ['message'],
], $set_webhook_payload, 'Webhook registration must send only the fixed contract.');
assertTelegramTransport(
    false,
    array_key_exists('drop_pending_updates', $set_webhook_payload),
    'Webhook registration must preserve pending updates.'
);
assertTelegramTransport(
    false,
    array_key_exists('privacy_mode', $set_webhook_payload),
    'Webhook registration must not touch privacy settings.'
);

$legacy_payload = telegramRequestPayload($history, 3);
assertTelegramTransport($legacy_markup, $legacy_payload['reply_markup'], 'Legacy reply markup must remain top-level.');
$rich_options_payload = telegramRequestPayload($history, 4);
assertTelegramTransport(42, $rich_options_payload['message_thread_id'], 'Thread ID must be forwarded.');
assertTelegramTransport(true, $rich_options_payload['disable_notification'], 'Notification flag must be forwarded.');
$composed_payload = telegramRequestPayload($history, 5);
assertTelegramTransport($legacy_markup, $composed_payload['reply_markup'], 'Composed reply markup must be forwarded.');
assertTelegramTransport(true, $composed_payload['protect_content'], 'Content protection must be forwarded.');
$plain_payload = telegramRequestPayload($history, 6);
assertTelegramTransport('<code>hello</code>', $plain_payload['text'], 'Plain message text must be forwarded unchanged.');
assertTelegramTransport('HTML', $plain_payload['parse_mode'] ?? null, 'Plain messages must allow only explicit HTML parsing.');
$processing_reaction = telegramRequestPayload($history, 7);
assertTelegramTransport(
    [['type' => 'emoji', 'emoji' => '👀']],
    $processing_reaction['reaction'],
    'Processing must use Telegram reaction objects.'
);
$cleared_reaction = telegramRequestPayload($history, 8);
assertTelegramTransport([], $cleared_reaction['reaction'], 'Completion must remove the processing reaction.');
$commands_payload = telegramRequestPayload($history, 9);
assertTelegramTransport([
    [
        'command' => 'account',
        'description' => 'Show a Stellar account',
    ],
    [
        'command' => 'help',
        'description' => 'How to use the bot',
    ],
], $commands_payload['commands'] ?? null, 'The default command menu must be English.');
assertTelegramTransport(false, array_key_exists('language_code', $commands_payload), 'Default commands must not be language-scoped.');
$russian_commands_payload = telegramRequestPayload($history, 10);
assertTelegramTransport([
    [
        'command' => 'account',
        'description' => 'Рассказать об аккаунте Stellar',
    ],
    [
        'command' => 'help',
        'description' => 'Как пользоваться ботом',
    ],
], $russian_commands_payload['commands'] ?? null, 'The Russian command menu must be localized.');
assertTelegramTransport('ru', $russian_commands_payload['language_code'] ?? null, 'Russian commands must use Telegram language scoping.');

$wrong_identity_history = [];
$WrongIdentityHandler = HandlerStack::create(new MockHandler([
    telegramOkResponse([
        'id' => 999999,
        'is_bot' => true,
        'username' => 'Another_robot',
    ]),
]));
$WrongIdentityHandler->push(Middleware::history($wrong_identity_history));
$WrongIdentityClient = new TelegramBotApiClient($Config, new Client([
    'handler' => $WrongIdentityHandler,
    'base_uri' => 'https://api.telegram.org/',
]));
$wrong_identity_rejected = false;
try {
    $WrongIdentityClient->setWebhook();
} catch (TelegramBotApiException $Exception) {
    $wrong_identity_rejected = $Exception->apiMethod() === 'setWebhook'
        && str_contains($Exception->getMessage(), 'different Telegram bot');
}
assertTelegramTransport(true, $wrong_identity_rejected, 'Webhook registration must verify the token identity.');
assertTelegramTransport(1, count($wrong_identity_history), 'A mismatched bot must be rejected before setWebhook.');
assertTelegramTransport(
    true,
    str_ends_with((string) $wrong_identity_history[0]['request']->getUri(), '/getMe'),
    'Bot identity verification must use getMe.'
);

$request_count_before_validation = count($history);
$unsupported_option_rejected = false;
try {
    $ApiClient->sendMessage('3718221', 'hello', ['parse_mode' => 'MarkdownV2']);
} catch (TelegramBotApiException $Exception) {
    $unsupported_option_rejected = $Exception->apiMethod() === 'sendMessage';
}
assertTelegramTransport(true, $unsupported_option_rejected, 'Unsupported parse modes must be rejected locally.');
assertTelegramTransport(
    $request_count_before_validation,
    count($history),
    'Local option validation must not call Telegram.'
);

$rate_limit_client = new TelegramBotApiClient($fake_token, new Client([
    'handler' => HandlerStack::create(new MockHandler([
        new Response(429, ['Content-Type' => 'application/json'], json_encode([
            'ok' => false,
            'error_code' => 429,
            'description' => 'Too Many Requests for ' . $fake_token,
            'parameters' => [
                'retry_after' => 17,
            ],
        ], JSON_THROW_ON_ERROR)),
    ])),
    'base_uri' => 'https://api.telegram.org/',
]));
$rate_limit_error = null;
try {
    $rate_limit_client->sendMessage('3718221', 'hello');
} catch (TelegramBotApiException $Exception) {
    $rate_limit_error = $Exception;
}
assertTelegramTransport(true, $rate_limit_error instanceof TelegramBotApiException, 'API failures must be typed.');
assertTelegramTransport(429, $rate_limit_error?->errorCode(), 'Telegram error_code must be available.');
assertTelegramTransport(17, $rate_limit_error?->retryAfter(), 'Telegram retry_after must be available.');
assertTelegramTransport(false, $rate_limit_error?->deliveryUncertain(), 'Explicit API rejection is not uncertain.');
assertTelegramTransport(429, $rate_limit_error?->httpStatus(), 'HTTP status must be available.');
assertTelegramTransport('sendMessage', $rate_limit_error?->apiMethod(), 'The failed API method must be available.');
assertTelegramTransport(
    false,
    str_contains((string) $rate_limit_error?->getMessage(), $fake_token),
    'Typed API errors must not expose the bot token.'
);
assertTelegramTransport(
    true,
    str_contains((string) $rate_limit_error?->getMessage(), '[redacted]'),
    'Typed API errors must mark redacted credentials explicitly.'
);

$transport_exception = new ConnectException(
    'Connection failed for https://api.telegram.org/bot' . $fake_token . '/sendMessage',
    new Request('POST', 'https://api.telegram.org/')
);
$transport_client = new TelegramBotApiClient($fake_token, new Client([
    'handler' => HandlerStack::create(new MockHandler([$transport_exception])),
    'base_uri' => 'https://api.telegram.org/',
]));
$transport_error = null;
try {
    $transport_client->sendMessage('3718221', 'hello');
} catch (TelegramBotApiException $Exception) {
    $transport_error = $Exception;
}
assertTelegramTransport(true, $transport_error?->deliveryMayHaveSucceeded(), 'A send transport failure is uncertain.');
assertTelegramTransport(
    false,
    str_contains((string) $transport_error?->getMessage(), $fake_token),
    'Transport exception details must not leak the request URI.'
);

$read_transport_client = new TelegramBotApiClient($fake_token, new Client([
    'handler' => HandlerStack::create(new MockHandler([
        new ConnectException('Connection failed', new Request('POST', 'https://api.telegram.org/')),
    ])),
    'base_uri' => 'https://api.telegram.org/',
]));
$read_transport_error = null;
try {
    $read_transport_client->getWebhookInfo();
} catch (TelegramBotApiException $Exception) {
    $read_transport_error = $Exception;
}
assertTelegramTransport(
    false,
    $read_transport_error?->deliveryUncertain(),
    'A read-only transport failure must not be marked as delivery uncertainty.'
);

fwrite(STDOUT, "Telegram bot transport regression tests passed.\n");
