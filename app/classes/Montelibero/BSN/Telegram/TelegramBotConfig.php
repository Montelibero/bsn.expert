<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use InvalidArgumentException;

final class TelegramBotConfig
{
    public const DEFAULT_BOT_USERNAME = 'BSN_robot';
    public const WEBHOOK_URL = 'https://bsn.expert/telegram/webhook';

    private readonly string $bot_token;
    private readonly string $bot_username;
    private readonly string $webhook_secret;
    private readonly string $admin_ids;

    /**
     * @param array<string, mixed>|null $environment
     */
    public function __construct(?array $environment = null)
    {
        $environment ??= $_ENV;

        $this->bot_token = self::readString($environment, 'TG_BOT_KEY');
        $this->bot_username = ltrim(
            self::readString($environment, 'TG_BOT_USERNAME', self::DEFAULT_BOT_USERNAME),
            '@'
        );
        $this->webhook_secret = self::readString($environment, 'TG_WEBHOOK_SECRET');
        $this->admin_ids = self::readString($environment, 'ADMINS_TG');
    }

    public function validate(): self
    {
        if (!self::isValidBotToken($this->bot_token)) {
            throw new InvalidArgumentException('TG_BOT_KEY is missing or has an invalid format.');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/D', $this->bot_username) !== 1) {
            throw new InvalidArgumentException('TG_BOT_USERNAME has an invalid format.');
        }
        if (!self::isValidWebhookSecret($this->webhook_secret)) {
            throw new InvalidArgumentException('TG_WEBHOOK_SECRET is missing or has an invalid format.');
        }

        $this->adminIds();

        return $this;
    }

    public function botToken(): string
    {
        return $this->bot_token;
    }

    public function botUsername(): string
    {
        return $this->bot_username;
    }

    public function webhookSecret(): string
    {
        return $this->webhook_secret;
    }

    public function webhookUrl(): string
    {
        return self::WEBHOOK_URL;
    }

    /**
     * @return list<string>
     */
    public function adminIds(): array
    {
        if ($this->admin_ids === '') {
            return [];
        }

        $admin_ids = [];
        foreach (explode(',', $this->admin_ids) as $admin_id) {
            $admin_id = trim($admin_id);
            if ($admin_id === '') {
                continue;
            }
            if (preg_match('/^[1-9]\d{0,18}$/D', $admin_id) !== 1) {
                throw new InvalidArgumentException('ADMINS_TG contains an invalid Telegram user ID.');
            }
            if (!in_array($admin_id, $admin_ids, true)) {
                $admin_ids[] = $admin_id;
            }
        }

        return $admin_ids;
    }

    public function isAdmin(int|string $telegram_user_id): bool
    {
        $telegram_user_id = trim((string) $telegram_user_id);
        if (preg_match('/^[1-9]\d{0,18}$/D', $telegram_user_id) !== 1) {
            return false;
        }

        return in_array($telegram_user_id, $this->adminIdsFailClosed(), true);
    }

    /** @return list<string> */
    public function adminIdsFailClosed(): array
    {
        try {
            return $this->adminIds();
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    public function hasValidWebhookSecret(): bool
    {
        return self::isValidWebhookSecret($this->webhook_secret);
    }

    public static function isValidBotToken(string $bot_token): bool
    {
        return preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $bot_token) === 1;
    }

    public static function isValidWebhookSecret(string $webhook_secret): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $webhook_secret) === 1;
    }

    /**
     * @param array<string, mixed> $environment
     */
    private static function readString(array $environment, string $name, string $default = ''): string
    {
        $value = $environment[$name] ?? $default;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
