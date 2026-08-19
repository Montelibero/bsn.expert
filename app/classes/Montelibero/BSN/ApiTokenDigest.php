<?php

declare(strict_types=1);

namespace Montelibero\BSN;

final class ApiTokenDigest
{
    public const ALGORITHM = 'sha256';
    private const HEX_LENGTH = 64;

    public static function fromToken(string $token): string
    {
        return hash(self::ALGORITHM, $token);
    }

    public static function isValid(mixed $digest): bool
    {
        return is_string($digest)
            && strlen($digest) === self::HEX_LENGTH
            && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }
}
