<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use Soneso\StellarSDK\Crypto\StrKey;

final class TelegramUpdateParser
{
    public const TYPE_ACCOUNT_INFO = 'account_info';
    public const TYPE_ACCOUNT_PROMPT = 'account_prompt';
    public const TYPE_ADMIN_COMMAND = 'admin_command';
    public const TYPE_VALIDATION_ERROR = 'validation_error';

    public const COMMAND_ACCOUNT = 'account';
    public const COMMAND_ACCOUNT_INFO = 'account_info';
    public const COMMAND_START = 'start';
    public const COMMAND_HELP = 'help';
    public const COMMAND_DAILY_REPORT_ON = 'daily_report_on';
    public const COMMAND_DAILY_REPORT_OFF = 'daily_report_off';

    public const ACCOUNT_PROMPT_TEXT = 'Про какой аккаунт вам рассказать?';
    public const ACCOUNT_PROMPT_TEXT_EN = 'Which account would you like me to tell you about?';

    private readonly string $bot_username;

    public function __construct(TelegramBotConfig $Config)
    {
        $bot_username = ltrim(trim($Config->botUsername()), '@');
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D', $bot_username) !== 1) {
            throw new \InvalidArgumentException('Invalid Telegram bot username.');
        }

        $this->bot_username = strtolower($bot_username);
    }

    /**
     * @param array<string, mixed> $update
     * @return array{
     *     update_id: string,
     *     type: self::TYPE_*,
     *     command: self::COMMAND_*,
     *     account_id: ?string,
     *     validation_error: ?string,
     *     help_context?: 'help_requested'|'invalid_start_payload',
     *     chat: array{id: string, type: string, title: ?string, username: ?string},
     *     user: array{id: string, username: ?string, name: ?string, language_code: ?string},
     *     message_id: int,
     *     message_date: int,
     *     message_thread_id: ?int,
     *     direct_messages_topic_id: ?string
     * }|null
     */
    public function parse(array $update): ?array
    {
        $update_id = $this->unsignedIntegerString($update['update_id'] ?? null);
        $message = $update['message'] ?? null;
        if ($update_id === null || !is_array($message)) {
            return null;
        }

        $from = $message['from'] ?? null;
        $chat = $message['chat'] ?? null;
        $text = $message['text'] ?? null;
        if (!is_array($from)
            || ($from['is_bot'] ?? false) === true
            || !is_array($chat)
            || !is_string($text)
        ) {
            return null;
        }

        $chat_type = $chat['type'] ?? null;
        if (!is_string($chat_type) || !in_array($chat_type, ['private', 'group', 'supergroup'], true)) {
            return null;
        }

        $chat_id = $this->integerString($chat['id'] ?? null);
        $user_id = $this->positiveIntegerString($from['id'] ?? null);
        $message_id = $this->positiveInteger($message['message_id'] ?? null);
        $message_date = $this->positiveInteger($message['date'] ?? null);
        if ($chat_id === null || $user_id === null || $message_id === null || $message_date === null) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $parsed = $this->parseText($text, $chat_type, $message['reply_to_message'] ?? null);
        if ($parsed === null) {
            return null;
        }

        $message_thread_id = $this->positiveInteger($message['message_thread_id'] ?? null);
        $direct_messages_topic_id = null;
        if (is_array($message['direct_messages_topic'] ?? null)) {
            $direct_messages_topic_id = $this->positiveIntegerString(
                $message['direct_messages_topic']['topic_id'] ?? null
            );
        }

        return $parsed + [
            'update_id' => $update_id,
            'chat' => [
                'id' => $chat_id,
                'type' => $chat_type,
                'title' => $this->cleanText($chat['title'] ?? null, 128),
                'username' => $this->cleanUsername($chat['username'] ?? null),
            ],
            'user' => [
                'id' => $user_id,
                'username' => $this->cleanUsername($from['username'] ?? null),
                'name' => $this->personName($from),
                'language_code' => $this->cleanLanguageCode($from['language_code'] ?? null),
            ],
            'message_id' => $message_id,
            'message_date' => $message_date,
            'message_thread_id' => $message_thread_id,
            'direct_messages_topic_id' => $direct_messages_topic_id,
        ];
    }

    /**
     * @return array{
     *     type: self::TYPE_*,
     *     command: self::COMMAND_*,
     *     account_id: ?string,
     *     validation_error: ?string
     * }|null
     */
    private function parseText(string $text, string $chat_type, mixed $reply_to_message): ?array
    {
        if (preg_match(
            '/\A\/(start|help)(?:@([A-Za-z][A-Za-z0-9_]{4,31}))?(?:\s+(.*))?\z/isuD',
            $text,
            $matches
        ) === 1) {
            $command = strtolower($matches[1]);
            $suffix = $matches[2] ?? '';
            if ($suffix !== '' && strtolower($suffix) !== $this->bot_username) {
                return null;
            }

            if ($command === self::COMMAND_HELP) {
                return $this->parsed(
                    self::TYPE_VALIDATION_ERROR,
                    self::COMMAND_ACCOUNT,
                    null,
                    'missing_account_id',
                    'help_requested'
                );
            }

            if ($chat_type !== 'private') {
                return null;
            }

            $payload = trim((string) ($matches[3] ?? ''));
            if ($payload === '') {
                return $this->parsed(
                    self::TYPE_VALIDATION_ERROR,
                    self::COMMAND_ACCOUNT,
                    null,
                    'missing_account_id',
                    'help_requested'
                );
            }

            $account_id = str_starts_with($payload, 'a_')
                ? $this->normalizeAccountId(substr($payload, 2))
                : null;

            return $account_id === null
                ? $this->parsed(
                    self::TYPE_VALIDATION_ERROR,
                    self::COMMAND_ACCOUNT,
                    null,
                    'invalid_account_id',
                    'invalid_start_payload'
                )
                : $this->parsed(self::TYPE_ACCOUNT_INFO, self::COMMAND_ACCOUNT, $account_id);
        }

        if ($this->isReplyToAccountPrompt($reply_to_message)) {
            $account_id = $this->normalizeAccountId($text);

            return $account_id === null
                ? $this->parsed(
                    self::TYPE_VALIDATION_ERROR,
                    self::COMMAND_ACCOUNT,
                    null,
                    'invalid_account_id'
                )
                : $this->parsed(self::TYPE_ACCOUNT_INFO, self::COMMAND_ACCOUNT, $account_id);
        }

        if ($chat_type === 'private') {
            $account_id = $this->normalizeAccountId($text);
            if ($account_id !== null) {
                return $this->parsed(self::TYPE_ACCOUNT_INFO, self::COMMAND_ACCOUNT, $account_id);
            }
        }

        if (preg_match(
            '/\A\/(account|account_info|daily_report_on|daily_report_off)(?:@([A-Za-z][A-Za-z0-9_]{4,31}))?(?:\s+(.*))?\z/isuD',
            $text,
            $matches
        ) !== 1) {
            return null;
        }

        $command = strtolower($matches[1]);
        $suffix = $matches[2] ?? '';
        if ($suffix !== '' && strtolower($suffix) !== $this->bot_username) {
            return null;
        }
        $argument = trim((string) ($matches[3] ?? ''));

        if (in_array($command, [self::COMMAND_ACCOUNT, self::COMMAND_ACCOUNT_INFO], true)) {
            if ($argument === '') {
                // Keep the durable payload compatible with workers deployed
                // before interactive account prompts were introduced.
                return $this->parsed(
                    self::TYPE_VALIDATION_ERROR,
                    self::COMMAND_ACCOUNT,
                    null,
                    'missing_account_id'
                );
            }

            $account_id = $this->normalizeAccountId($argument);
            if ($account_id === null) {
                return $this->parsed(
                    self::TYPE_VALIDATION_ERROR,
                    self::COMMAND_ACCOUNT,
                    null,
                    'invalid_account_id'
                );
            }

            return $this->parsed(self::TYPE_ACCOUNT_INFO, self::COMMAND_ACCOUNT, $account_id);
        }

        if ($chat_type !== 'private') {
            return null;
        }
        if ($argument !== '') {
            return $this->parsed(
                self::TYPE_VALIDATION_ERROR,
                $command,
                null,
                'unexpected_argument'
            );
        }

        return $this->parsed(self::TYPE_ADMIN_COMMAND, $command);
    }

    private function isReplyToAccountPrompt(mixed $reply_to_message): bool
    {
        if (!is_array($reply_to_message)) {
            return false;
        }

        $text = $reply_to_message['text'] ?? null;
        if (!in_array($text, [self::ACCOUNT_PROMPT_TEXT, self::ACCOUNT_PROMPT_TEXT_EN], true)) {
            return false;
        }

        $from = $reply_to_message['from'] ?? null;
        if (!is_array($from) || ($from['is_bot'] ?? null) !== true) {
            return false;
        }

        $username = $this->cleanUsername($from['username'] ?? null);

        return $username !== null && strtolower($username) === $this->bot_username;
    }

    /**
     * @return array{
     *     type: self::TYPE_*,
     *     command: self::COMMAND_*,
     *     account_id: ?string,
     *     validation_error: ?string,
     *     help_context?: 'help_requested'|'invalid_start_payload'
     * }
     */
    private function parsed(
        string $type,
        string $command,
        ?string $account_id = null,
        ?string $validation_error = null,
        ?string $help_context = null,
    ): array {
        $parsed = [
            'type' => $type,
            'command' => $command,
            'account_id' => $account_id,
            'validation_error' => $validation_error,
        ];

        if ($help_context !== null) {
            $parsed['help_context'] = $help_context;
        }

        return $parsed;
    }

    private function normalizeAccountId(string $value): ?string
    {
        $account_id = strtoupper(trim($value));
        if (preg_match('/\AG[A-Z2-7]{55}\z/D', $account_id) !== 1) {
            return null;
        }

        try {
            $public_key = StrKey::decodeAccountId($account_id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return strlen($public_key) === 32 && StrKey::encodeAccountId($public_key) === $account_id
            ? $account_id
            : null;
    }

    private function personName(array $from): ?string
    {
        $parts = [];
        foreach (['first_name', 'last_name'] as $field) {
            $part = $this->cleanText($from[$field] ?? null, 64);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : mb_substr(implode(' ', $parts), 0, 128, 'UTF-8');
    }

    private function cleanUsername(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = ltrim(trim($value), '@');

        return preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D', $value) === 1 ? $value : null;
    }

    private function cleanLanguageCode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match('/\A[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/D', $value) === 1
            ? mb_strtolower($value, 'UTF-8')
            : null;
    }

    private function cleanText(mixed $value, int $max_length): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max_length, 'UTF-8');
    }

    private function integerString(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) === 1
            ? $value
            : null;
    }

    private function unsignedIntegerString(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            ? $value
            : null;
    }

    private function positiveIntegerString(mixed $value): ?string
    {
        $value = $this->unsignedIntegerString($value);

        return $value !== null && $value !== '0' ? $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
