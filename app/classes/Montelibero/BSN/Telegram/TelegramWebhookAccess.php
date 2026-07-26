<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

final class TelegramWebhookAccess
{
    public function __construct(
        private readonly TelegramBotConfig $Config,
    ) {
    }

    public function isAllowed(?string $secret_header): bool
    {
        if (!$this->Config->hasValidWebhookSecret() || $secret_header === null) {
            return false;
        }

        return hash_equals($this->Config->webhookSecret(), $secret_header);
    }
}
