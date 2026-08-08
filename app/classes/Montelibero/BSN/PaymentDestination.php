<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Xdr\XdrBuffer;
use Soneso\StellarSDK\Xdr\XdrMuxedAccount;
use Soneso\StellarSDK\Xdr\XdrMuxedAccountMed25519;

final readonly class PaymentDestination
{
    private function __construct(
        public string $address,
        public string $account_id,
        private XdrMuxedAccount $XdrAccount,
    ) {
    }

    public static function fromAddress(string $address): self
    {
        $address = strtoupper(trim($address));

        if (preg_match('/\AG[A-Z2-7]{55}\z/D', $address) === 1) {
            $ed25519 = StrKey::decodeAccountId($address);
            if (strlen($ed25519) !== 32) {
                throw new \InvalidArgumentException('Invalid Stellar account payload.');
            }

            return new self($address, $address, new XdrMuxedAccount($ed25519));
        }

        if (preg_match('/\AM[A-Z2-7]{68}\z/D', $address) === 1) {
            $payload = StrKey::decodeMuxedAccountId($address);
            if (strlen($payload) !== 40) {
                throw new \InvalidArgumentException('Invalid muxed Stellar account payload.');
            }

            $Muxed = XdrMuxedAccountMed25519::decodeInverted(new XdrBuffer($payload));
            $account_id = StrKey::encodeAccountId($Muxed->getEd25519());

            return new self($address, $account_id, new XdrMuxedAccount(null, $Muxed));
        }

        throw new \InvalidArgumentException('Invalid Stellar payment destination.');
    }

    public function isMuxed(): bool
    {
        return str_starts_with($this->address, 'M');
    }

    public function toXdr(): XdrMuxedAccount
    {
        return $this->XdrAccount;
    }
}
