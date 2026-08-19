<?php

declare(strict_types=1);

namespace Montelibero\BSN;

final class ApiAuthenticationException extends \RuntimeException
{
    public const MISSING_TOKEN = 'Missing Bearer token';
    public const INVALID_TOKEN = 'Invalid API key';
}
