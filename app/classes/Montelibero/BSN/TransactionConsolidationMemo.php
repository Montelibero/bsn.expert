<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Xdr\XdrBuffer;
use Soneso\StellarSDK\Xdr\XdrMemo;
use Soneso\StellarSDK\Xdr\XdrMemoType;

final readonly class TransactionConsolidationMemo
{
    private function __construct(
        public string $type,
        public ?string $value,
        public string $label,
        private string $rawXdr,
    ) {
    }

    public static function none(): self
    {
        $Memo = new XdrMemo(new XdrMemoType(XdrMemoType::MEMO_NONE));

        return self::fromXdr($Memo);
    }

    /**
     * Empty input means no memo. Exactly 64 hexadecimal characters mean a
     * SHA-256 memo; every other non-empty value is a UTF-8 text memo.
     */
    public static function fromCustom(string $value): self
    {
        if ($value === '') {
            return self::none();
        }

        if (preg_match('/\A[0-9a-fA-F]{64}\z/D', $value) === 1) {
            $Memo = new XdrMemo(new XdrMemoType(XdrMemoType::MEMO_HASH));
            $Memo->setHash(hex2bin($value));

            return self::fromXdr($Memo);
        }

        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Text memo must be valid UTF-8.');
        }
        if (strlen($value) > XdrMemo::VALUE_TEXT_MAX_SIZE) {
            throw new \InvalidArgumentException('Text memo must not exceed 28 bytes.');
        }

        $Memo = new XdrMemo(new XdrMemoType(XdrMemoType::MEMO_TEXT));
        $Memo->setText($value);

        return self::fromXdr($Memo);
    }

    public static function fromXdr(XdrMemo $Memo): self
    {
        $raw = $Memo->encode();
        $type = $Memo->getType()->getValue();

        return match ($type) {
            XdrMemoType::MEMO_NONE => new self('none', null, 'No memo', $raw),
            XdrMemoType::MEMO_TEXT => self::textMemo($Memo, $raw),
            XdrMemoType::MEMO_ID => self::idMemo($raw),
            XdrMemoType::MEMO_HASH => self::hashMemo('hash', $Memo->getHash(), $raw),
            XdrMemoType::MEMO_RETURN => self::hashMemo('return', $Memo->getReturnHash(), $raw),
            default => throw new \InvalidArgumentException('Unsupported memo type.'),
        };
    }

    public function toXdr(): XdrMemo
    {
        $Buffer = new class($this->rawXdr) extends XdrBuffer {
            public function atEnd(): bool
            {
                return $this->position === $this->size;
            }
        };
        $Memo = XdrMemo::decode($Buffer);
        if (!$Buffer->atEnd()) {
            throw new \LogicException('Memo XDR contains trailing data.');
        }

        return $Memo;
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->rawXdr);
    }

    private static function textMemo(XdrMemo $Memo, string $raw): self
    {
        $value = $Memo->getText();
        if ($value === null) {
            throw new \InvalidArgumentException('Text memo has no value.');
        }

        $display = preg_match('//u', $value) === 1
            ? $value
            : 'base64:' . base64_encode($value);

        return new self('text', $value, 'TEXT: ' . $display, $raw);
    }

    private static function idMemo(string $raw): self
    {
        if (strlen($raw) !== 12) {
            throw new \InvalidArgumentException('Invalid ID memo XDR.');
        }

        $value = (new BigInteger(substr($raw, 4, 8), 256))->toString();

        return new self('id', $value, 'ID: ' . $value, $raw);
    }

    private static function hashMemo(string $type, ?string $value, string $raw): self
    {
        if ($value === null || strlen($value) !== 32) {
            throw new \InvalidArgumentException('Hash memo must contain exactly 32 bytes.');
        }

        $hex = bin2hex($value);

        return new self($type, $hex, strtoupper($type) . ': ' . $hex, $raw);
    }
}
