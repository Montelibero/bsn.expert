<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\Xdr\XdrMemo;

final readonly class PaymentMemo
{
    private function __construct(
        public Memo $memo,
        public string $type,
        public ?string $value,
    ) {
    }

    public static function fromInput(string $input): self
    {
        $input = trim($input);
        if ($input === '') {
            return new self(Memo::none(), 'none', null);
        }

        if (preg_match('/\A[0-9a-fA-F]{64}\z/D', $input) === 1) {
            $bytes = hex2bin($input);
            if ($bytes === false) {
                throw new \InvalidArgumentException('Invalid hash memo.');
            }

            $normalized = strtolower($input);

            return new self(Memo::hash($bytes), 'hash', $normalized);
        }

        if (preg_match('/\A(?:0|[1-9]\d*)\z/D', $input) === 1) {
            if (!self::fitsPhpInteger($input)) {
                throw new \InvalidArgumentException('Invalid ID memo.');
            }

            return new self(Memo::id((int) $input), 'id', $input);
        }

        if (preg_match('//u', $input) !== 1 || strlen($input) > XdrMemo::VALUE_TEXT_MAX_SIZE) {
            throw new \InvalidArgumentException('Invalid text memo.');
        }

        return new self(Memo::text($input), 'text', $input);
    }

    private static function fitsPhpInteger(string $value): bool
    {
        $maximum = (string) PHP_INT_MAX;

        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
    }
}
