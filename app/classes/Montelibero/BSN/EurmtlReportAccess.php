<?php

namespace Montelibero\BSN;

use Soneso\StellarSDK\Responses\Account\AccountSignerResponse;
use Soneso\StellarSDK\StellarSDK;

class EurmtlReportAccess
{
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly CurrentUser $CurrentUser,
        private readonly StellarSDK $Stellar,
    ) {
    }

    public function canView(string $issuer): bool
    {
        $account_id = $this->CurrentUser->getAccountId();
        if (!is_string($account_id) || !BSN::validateStellarAccountIdFormat($account_id)) {
            return false;
        }

        return isset($this->fetchIssuerSignerIds($issuer)[$account_id]);
    }

    public function isAuthenticated(): bool
    {
        return $this->CurrentUser->isAuthorized();
    }

    private function fetchIssuerSignerIds(string $issuer): array
    {
        $cache_key = 'eurmtl_report_access_signers_' . $issuer;
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cache_key, $success);
            if ($success && is_array($cached)) {
                return $cached;
            }
        }

        $signers = [];
        /** @var AccountSignerResponse $Signer */
        foreach ($this->Stellar->requestAccount($issuer)->getSigners() as $Signer) {
            if ($Signer->getType() !== 'ed25519_public_key' || (int) $Signer->getWeight() <= 0) {
                continue;
            }
            if ($Signer->getKey() === $issuer) {
                continue;
            }

            $signers[$Signer->getKey()] = true;
        }

        if (function_exists('apcu_store')) {
            apcu_store($cache_key, $signers, self::CACHE_TTL);
        }

        return $signers;
    }
}
