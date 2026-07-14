<?php

namespace Montelibero\BSN;

use Soneso\StellarSDK\Responses\Account\AccountSignerResponse;
use Soneso\StellarSDK\StellarSDK;

class CrowdAccess
{
    public function __construct(
        private readonly CurrentUser $CurrentUser,
        private readonly CrowdConfig $Config,
        private readonly StellarSDK $Stellar,
    ) {
    }

    public function canManage(): bool
    {
        $account_id = $this->CurrentUser->getAccountId();
        $issuer = $this->Config->issuer();
        if (
            !is_string($account_id)
            || !BSN::validateStellarAccountIdFormat($account_id)
            || !is_string($issuer)
            || !BSN::validateStellarAccountIdFormat($issuer)
        ) {
            return false;
        }

        $account_id = strtoupper($account_id);
        $issuer = strtoupper($issuer);
        if (hash_equals($issuer, $account_id)) {
            return true;
        }

        /** @var AccountSignerResponse $Signer */
        foreach ($this->Stellar->requestAccount($issuer)->getSigners() as $Signer) {
            if ($Signer->getType() !== 'ed25519_public_key' || (int) $Signer->getWeight() <= 0) {
                continue;
            }
            if (hash_equals(strtoupper($Signer->getKey()), $account_id)) {
                return true;
            }
        }

        return false;
    }
}
