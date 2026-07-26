<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

final class TelegramBotApiClient
{
    public const REACTION_PROCESSING = '👀';
    public const REACTION_SUCCESS = '👌';

    private const MESSAGE_OPTION_NAMES = [
        'message_thread_id',
        'direct_messages_topic_id',
        'reply_parameters',
        'disable_notification',
        'protect_content',
        'reply_markup',
    ];

    private const REPLY_MARKUP_NAMES = [
        'inline_keyboard',
        'keyboard',
        'remove_keyboard',
        'force_reply',
        'selective',
        'resize_keyboard',
        'one_time_keyboard',
        'input_field_placeholder',
        'is_persistent',
    ];

    private readonly ClientInterface $HttpClient;
    private readonly string $bot_token;
    private readonly ?TelegramBotConfig $Config;

    public function __construct(string|TelegramBotConfig $bot_token, ?ClientInterface $HttpClient = null)
    {
        if ($bot_token instanceof TelegramBotConfig) {
            $this->Config = $bot_token;
            $this->bot_token = $bot_token->botToken();
        } else {
            $this->Config = null;
            $this->bot_token = trim($bot_token);
        }
        $this->HttpClient = $HttpClient ?? new Client([
            'base_uri' => 'https://api.telegram.org/',
            'connect_timeout' => 3.0,
            'timeout' => 10.0,
            'http_errors' => false,
        ]);
    }

    public function isConfigured(): bool
    {
        return TelegramBotConfig::isValidBotToken($this->bot_token);
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(): array
    {
        $response = $this->request('getWebhookInfo', [], false);
        $result = $response['result'];
        if (!is_array($result)) {
            throw $this->unexpectedResult('getWebhookInfo', false, $response['http_status']);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function getMe(): array
    {
        $response = $this->request('getMe', [], false);
        $result = $response['result'];
        if (!is_array($result)) {
            throw $this->unexpectedResult('getMe', false, $response['http_status']);
        }

        return $result;
    }

    public function setWebhook(?TelegramBotConfig $Config = null): bool
    {
        $Config ??= $this->Config ?? new TelegramBotConfig();
        $Config->validate();

        if (!hash_equals($this->bot_token, $Config->botToken())) {
            throw new TelegramBotApiException(
                'Telegram bot configuration does not match the API client.',
                api_method: 'setWebhook'
            );
        }

        $identity = $this->getMe();
        $actual_username = is_string($identity['username'] ?? null)
            ? ltrim(trim($identity['username']), '@')
            : '';
        if (($identity['is_bot'] ?? false) !== true
            || $actual_username === ''
            || strcasecmp($actual_username, $Config->botUsername()) !== 0
        ) {
            throw new TelegramBotApiException(
                'TG_BOT_KEY belongs to a different Telegram bot than TG_BOT_USERNAME.',
                api_method: 'setWebhook'
            );
        }

        $response = $this->request(
            'setWebhook',
            [
                'url' => TelegramBotConfig::WEBHOOK_URL,
                'secret_token' => $Config->webhookSecret(),
                'allowed_updates' => ['message'],
            ],
            true,
            [$Config->webhookSecret()]
        );
        if ($response['result'] !== true) {
            throw $this->unexpectedResult('setWebhook', true, $response['http_status']);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $rich_message
     * @param array<string, mixed> $reply_markup_or_options
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendRichMessage(
        int|string $chat_id,
        array $rich_message,
        array $reply_markup_or_options = [],
        array $options = [],
    ): array {
        if ($rich_message === []) {
            throw new TelegramBotApiException(
                'The rich message must not be empty.',
                api_method: 'sendRichMessage'
            );
        }

        $chat_id = $this->normalizeChatId($chat_id, 'sendRichMessage');
        $message_options = $this->richMessageOptions($reply_markup_or_options, $options);

        $payload = [
            'chat_id' => $chat_id,
            'rich_message' => $rich_message,
        ];
        foreach ($message_options as $name => $value) {
            $payload[$name] = $value;
        }

        $response = $this->request('sendRichMessage', $payload, true);
        $result = $response['result'];
        if (!is_array($result)) {
            throw $this->unexpectedResult('sendRichMessage', true, $response['http_status']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendMessage(int|string $chat_id, string $text, array $options = []): array
    {
        if ($text === '') {
            throw new TelegramBotApiException(
                'The message text must not be empty.',
                api_method: 'sendMessage'
            );
        }

        $payload = [
            'chat_id' => $this->normalizeChatId($chat_id, 'sendMessage'),
            'text' => $text,
        ];
        foreach ($this->validateMessageOptions($options, 'sendMessage') as $name => $value) {
            $payload[$name] = $value;
        }

        $response = $this->request('sendMessage', $payload, true);
        $result = $response['result'];
        if (!is_array($result)) {
            throw $this->unexpectedResult('sendMessage', true, $response['http_status']);
        }

        return $result;
    }

    public function setMessageReaction(int|string $chat_id, int $message_id, string $emoji): bool
    {
        if ($message_id <= 0) {
            throw new TelegramBotApiException(
                'Telegram message ID must be a positive integer.',
                api_method: 'setMessageReaction'
            );
        }
        if (!in_array($emoji, [self::REACTION_PROCESSING, self::REACTION_SUCCESS], true)) {
            throw new TelegramBotApiException(
                'Only the processing and success reactions are supported.',
                api_method: 'setMessageReaction'
            );
        }

        $response = $this->request(
            'setMessageReaction',
            [
                'chat_id' => $this->normalizeChatId($chat_id, 'setMessageReaction'),
                'message_id' => $message_id,
                'reaction' => [
                    [
                        'type' => 'emoji',
                        'emoji' => $emoji,
                    ],
                ],
            ],
            true
        );
        if ($response['result'] !== true) {
            throw $this->unexpectedResult('setMessageReaction', true, $response['http_status']);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $reply_markup_or_options
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function richMessageOptions(array $reply_markup_or_options, array $options): array
    {
        if ($options === []) {
            if ($this->isReplyMarkup($reply_markup_or_options)) {
                return ['reply_markup' => $reply_markup_or_options];
            }

            return $this->validateMessageOptions($reply_markup_or_options, 'sendRichMessage');
        }

        if ($this->isReplyMarkup($reply_markup_or_options)) {
            if (array_key_exists('reply_markup', $options)) {
                throw new TelegramBotApiException(
                    'Reply markup was provided twice.',
                    api_method: 'sendRichMessage'
                );
            }
            $options['reply_markup'] = $reply_markup_or_options;
        } else {
            $duplicates = array_intersect_key($reply_markup_or_options, $options);
            if ($duplicates !== []) {
                throw new TelegramBotApiException(
                    'A Telegram message option was provided twice.',
                    api_method: 'sendRichMessage'
                );
            }
            $options = array_merge($reply_markup_or_options, $options);
        }

        return $this->validateMessageOptions($options, 'sendRichMessage');
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function isReplyMarkup(array $candidate): bool
    {
        if ($candidate === []) {
            return false;
        }

        foreach (array_keys($candidate) as $name) {
            if (!is_string($name) || !in_array($name, self::REPLY_MARKUP_NAMES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateMessageOptions(array $options, string $api_method): array
    {
        foreach ($options as $name => $value) {
            if (!is_string($name) || !in_array($name, self::MESSAGE_OPTION_NAMES, true)) {
                throw new TelegramBotApiException(
                    'An unsupported Telegram message option was provided.',
                    api_method: $api_method
                );
            }

            if (in_array($name, ['message_thread_id', 'direct_messages_topic_id'], true)) {
                if (!is_int($value) || $value <= 0) {
                    throw new TelegramBotApiException(
                        sprintf('%s must be a positive integer.', $name),
                        api_method: $api_method
                    );
                }
                continue;
            }

            if (in_array($name, ['disable_notification', 'protect_content'], true)) {
                if (!is_bool($value)) {
                    throw new TelegramBotApiException(
                        sprintf('%s must be a boolean.', $name),
                        api_method: $api_method
                    );
                }
                continue;
            }

            if (!is_array($value)) {
                throw new TelegramBotApiException(
                    sprintf('%s must be an object.', $name),
                    api_method: $api_method
                );
            }
        }

        return $options;
    }

    private function normalizeChatId(int|string $chat_id, string $api_method): string
    {
        $chat_id = trim((string) $chat_id);
        if (
            preg_match('/^-?[1-9]\d*$/D', $chat_id) !== 1
            && preg_match('/^@[A-Za-z][A-Za-z0-9_]{4,31}$/D', $chat_id) !== 1
        ) {
            throw new TelegramBotApiException(
                'Telegram chat ID is missing or has an invalid format.',
                api_method: $api_method
            );
        }

        return $chat_id;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $additional_secrets
     * @return array{result: mixed, http_status: int}
     */
    private function request(
        string $api_method,
        array $payload,
        bool $delivery_uncertain_on_transport,
        array $additional_secrets = [],
    ): array {
        if (!$this->isConfigured()) {
            throw new TelegramBotApiException(
                'TG_BOT_KEY is missing or has an invalid format.',
                api_method: $api_method
            );
        }

        $request_options = [
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];
        if ($payload !== []) {
            $request_options['json'] = $payload;
        }

        try {
            $Response = $this->HttpClient->request(
                'POST',
                '/bot' . $this->bot_token . '/' . $api_method,
                $request_options
            );
        } catch (Throwable) {
            throw new TelegramBotApiException(
                'Не удалось связаться с Telegram Bot API.',
                delivery_uncertain: $delivery_uncertain_on_transport,
                api_method: $api_method
            );
        }

        $http_status = $Response->getStatusCode();
        try {
            $decoded = json_decode(
                (string) $Response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new TelegramBotApiException(
                sprintf('Telegram Bot API returned invalid JSON (HTTP %d).', $http_status),
                delivery_uncertain: $delivery_uncertain_on_transport,
                http_status: $http_status,
                api_method: $api_method
            );
        }

        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $description = is_array($decoded)
                ? (string) ($decoded['description'] ?? 'unknown error')
                : 'unknown error';
            $error_code = is_array($decoded)
                ? $this->optionalInteger($decoded['error_code'] ?? null)
                : null;
            $parameters = is_array($decoded['parameters'] ?? null)
                ? $decoded['parameters']
                : [];
            $retry_after = $this->optionalInteger($parameters['retry_after'] ?? null);

            throw new TelegramBotApiException(
                sprintf(
                    'Telegram Bot API rejected the request (HTTP %d): %s',
                    $http_status,
                    $this->sanitizeDescription($description, $additional_secrets)
                ),
                error_code: $error_code,
                retry_after: $retry_after,
                delivery_uncertain: false,
                http_status: $http_status,
                api_method: $api_method
            );
        }

        return [
            'result' => $decoded['result'] ?? null,
            'http_status' => $http_status,
        ];
    }

    private function unexpectedResult(
        string $api_method,
        bool $delivery_uncertain,
        int $http_status,
    ): TelegramBotApiException {
        return new TelegramBotApiException(
            sprintf('Telegram Bot API returned an unexpected result (HTTP %d).', $http_status),
            delivery_uncertain: $delivery_uncertain,
            http_status: $http_status,
            api_method: $api_method
        );
    }

    private function optionalInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
            return null;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($filtered) ? $filtered : null;
    }

    /**
     * @param list<string> $additional_secrets
     */
    private function sanitizeDescription(string $description, array $additional_secrets = []): string
    {
        $secrets = array_merge([$this->bot_token], $additional_secrets);
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $description = str_replace([$secret, rawurlencode($secret)], '[redacted]', $description);
        }
        $description = preg_replace('/\b\d+:[A-Za-z0-9_-]+\b/', '[redacted]', $description)
            ?? 'unknown error';
        $description = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $description) ?? 'unknown error';
        $description = trim($description);

        return mb_substr($description === '' ? 'unknown error' : $description, 0, 300, 'UTF-8');
    }
}
