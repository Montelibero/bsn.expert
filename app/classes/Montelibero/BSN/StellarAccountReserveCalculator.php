<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;

final class StellarAccountReserveCalculator
{
    private const STROOPS_PER_XLM = 10000000;

    public function __construct(private readonly StellarSDK $Stellar)
    {
    }

    public function fetchBaseReserveXlm(): string
    {
        $ledgers = $this->Stellar->ledgers()->order('desc')->limit(1)->execute();
        $latest = $ledgers->getLedgers()->toArray()[0];

        return bcdiv((string) $latest->getBaseReserveInStroops(), (string) self::STROOPS_PER_XLM, 7);
    }

    public function calculateAvailableXlm(AccountResponse $Account, ?string $base_reserve_xlm = null): string
    {
        $base_reserve_xlm ??= $this->fetchBaseReserveXlm();
        $native_balance = '0.0000000';
        $native_selling_liabilities = '0.0000000';

        /** @var AccountBalanceResponse $Balance */
        foreach ($Account->getBalances() as $Balance) {
            if ($Balance->getAssetType() !== Asset::TYPE_NATIVE) {
                continue;
            }

            $native_balance = $Balance->getBalance();
            $native_selling_liabilities = $Balance->getSellingLiabilities() ?? '0.0000000';
            break;
        }

        $reserve_entries = 2
            + $Account->getSubentryCount()
            + $Account->getNumSponsoring()
            - $Account->getNumSponsored();
        $reserve_entries = max(0, $reserve_entries);
        $minimum_balance = bcmul((string) $reserve_entries, $base_reserve_xlm, 7);

        return bcsub(bcsub($native_balance, $minimum_balance, 7), $native_selling_liabilities, 7);
    }

    public function requiredReserveForNewEntries(int $entries_count, ?string $base_reserve_xlm = null): string
    {
        if ($entries_count <= 0) {
            return '0.0000000';
        }

        $base_reserve_xlm ??= $this->fetchBaseReserveXlm();

        return bcmul((string) $entries_count, $base_reserve_xlm, 7);
    }
}
