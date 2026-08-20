<?php

declare(strict_types=1);

namespace Montelibero\BSN;

final class ApiTokenDigest
{
    public const ALGORITHM = 'sha256';
    private const HEX_LENGTH = 64;
    private const FINGERPRINT_HEX_LENGTH = 12;

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

    public static function fingerprintFromDigest(mixed $digest): string
    {
        if (!self::isValid($digest)) {
            return '';
        }

        return self::ALGORITHM . ':' . substr($digest, 0, self::FINGERPRINT_HEX_LENGTH);
    }
}
