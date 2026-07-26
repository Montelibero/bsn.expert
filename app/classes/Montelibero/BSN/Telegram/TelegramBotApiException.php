<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use RuntimeException;

final class TelegramBotApiException extends RuntimeException
{
    public readonly ?int $error_code;
    public readonly ?int $retry_after;
    public readonly bool $delivery_uncertain;
    public readonly ?int $http_status;
    public readonly string $api_method;

    public function __construct(
        string $message,
        ?int $error_code = null,
        ?int $retry_after = null,
        bool $delivery_uncertain = false,
        ?int $http_status = null,
        string $api_method = '',
    ) {
        parent::__construct($message, $error_code ?? 0);

        $this->error_code = $error_code;
        $this->retry_after = $retry_after;
        $this->delivery_uncertain = $delivery_uncertain;
        $this->http_status = $http_status;
        $this->api_method = $api_method;
    }

    public function errorCode(): ?int
    {
        return $this->error_code;
    }

    public function retryAfter(): ?int
    {
        return $this->retry_after;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retry_after;
    }

    public function deliveryUncertain(): bool
    {
        return $this->delivery_uncertain;
    }

    public function isDeliveryUncertain(): bool
    {
        return $this->delivery_uncertain;
    }

    public function deliveryMayHaveSucceeded(): bool
    {
        return $this->delivery_uncertain;
    }

    public function httpStatus(): ?int
    {
        return $this->http_status;
    }

    public function apiMethod(): string
    {
        return $this->api_method;
    }
}
