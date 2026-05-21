<?php

namespace Montelibero\BSN;

use Montelibero\BSN\Controllers\TokensController;
use GuzzleHttp\Client;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Asset\AssetResponse;
use Soneso\StellarSDK\Responses\ClaimableBalances\ClaimableBalanceResponse;
use Soneso\StellarSDK\Responses\LiquidityPools\LiquidityPoolResponse;
use Soneso\StellarSDK\Responses\Offers\OfferResponse;
use Soneso\StellarSDK\StellarSDK;
use Throwable;

class EurmtlReportService
{
    private const CACHE_KEY_PREFIX = 'eurmtl_report_snapshot:v15';
    private const CACHE_TTL = 3600;
    private const FRESH_SNAPSHOT_SECONDS = 60;
    private const STALE_CACHE_SECONDS = 21600;
    private const MARKET_HOLDER_MIN_DISPLAY = '10.0000000';
    private const SCALE = 7;
    private const RATIO_SCALE = 14;
    private const RATE_SCALE = 14;

    private array $known_account_ids = [];
    private ?Client $HttpClient = null;

    public function __construct(
        private readonly BSN $BSN,
        private readonly StellarSDK $Stellar,
        private readonly MongoCacheManager $CacheManager,
        private readonly CurrentUser $CurrentUser,
        private readonly EurmtlReportConfig $Config,
        private readonly TokensController $TokensController,
        private readonly StellarTomlImageManager $TomlImageManager,
    ) {
    }

    public function fetchSnapshot(bool $force_refresh): array
    {
        $config = $this->Config->snapshot();
        $cache_key = $this->makeSnapshotCacheKey($config);
        $cached = $this->cacheFetch($cache_key);

        if (!$force_refresh && is_array($cached) && ($cached['config_hash'] ?? null) === $this->configHash($config)) {
            $cached['from_cache'] = true;
            $cached['warning'] = null;
            return $this->finalizeSnapshot($cached);
        }

        try {
            $snapshot = $this->buildSnapshot($config);
            $this->cacheStore($cache_key, $snapshot, self::CACHE_TTL);
            $snapshot['from_cache'] = false;
            $snapshot['warning'] = null;
            return $this->finalizeSnapshot($snapshot);
        } catch (HorizonRequestException|Throwable $Exception) {
            if (is_array($cached)) {
                $cached['from_cache'] = true;
                $cached['warning'] = $Exception->getMessage();
                return $this->finalizeSnapshot($cached);
            }

            return $this->finalizeSnapshot([
                'fetched_at' => null,
                'config' => $config,
                'config_hash' => $this->configHash($config),
                'assets' => [],
                'accounts' => [],
                'treasuries' => [],
                'market_holders' => $this->emptyMarketHolders(),
                'pools' => [],
                'market_maker' => [],
                'claimable_balances' => [],
                'unknown' => [],
                'totals' => [],
                'from_cache' => false,
                'warning' => $Exception->getMessage(),
            ]);
        }
    }

    public function canRefreshSnapshot(): bool
    {
        return $this->CurrentUser->getMemberLevel() >= 4;
    }

    private function buildSnapshot(array $config): array
    {
        $this->known_account_ids = array_fill_keys(array_keys($this->BSN->getAccounts()), true);

        $eurmtl_asset = $this->makeAsset(EurmtlReportConfig::EURMTL_CODE);
        $eurdebt_asset = $this->makeAsset(EurmtlReportConfig::EURDEBT_CODE);
        $asset_stats = [
            EurmtlReportConfig::EURMTL_CODE => $this->fetchAssetStats(EurmtlReportConfig::EURMTL_CODE),
            EurmtlReportConfig::EURDEBT_CODE => $this->fetchAssetStats(EurmtlReportConfig::EURDEBT_CODE),
        ];

        $eurdebt_holders = $this->collectAssetHolders($eurdebt_asset);
        $treasury_ids = array_fill_keys(
            array_diff(array_keys($eurdebt_holders), EurmtlReportConfig::MARKET_MAKER_ACCOUNTS),
            true
        );
        $pools = $this->collectPools($treasury_ids);
        $pool_eurmtl_by_account = $this->collectPoolEurmtlByAccount($pools);
        $tracked_account_ids = array_values(array_unique(array_merge(
            array_keys($eurdebt_holders),
            EurmtlReportConfig::MARKET_MAKER_ACCOUNTS,
            [EurmtlReportConfig::ISSUER],
            array_keys($pool_eurmtl_by_account)
        )));
        $eurmtl_holders = array_replace(
            $this->collectTopAssetHolders(EurmtlReportConfig::EURMTL_CODE, self::MARKET_HOLDER_MIN_DISPLAY),
            $this->collectBalancesForAccounts($tracked_account_ids, $eurmtl_asset)
        );
        $accounts = $this->buildAccounts($eurmtl_holders, $eurdebt_holders, $treasury_ids, $pool_eurmtl_by_account);
        $market_holders = $this->buildMarketHolders(
            $eurmtl_holders,
            $pool_eurmtl_by_account,
            $treasury_ids,
            $asset_stats[EurmtlReportConfig::EURMTL_CODE]['direct_balances']
        );
        $treasuries = array_values(array_filter(
            $accounts,
            static fn(array $account): bool => ($account['classification'] ?? null) === 'legacy_treasury'
        ));
        usort($treasuries, static fn(array $a, array $b): int => bccomp($b['market_amount'], $a['market_amount'], self::SCALE));

        $claimable_eurmtl = $this->collectClaimableBalances($eurmtl_asset);
        $claimable_eurdebt = $this->collectClaimableBalances($eurdebt_asset);
        $market_maker = $this->buildMarketMakerBlock($accounts, $pools);
        $market_maker['portfolio'] = $this->collectMarketMakerPortfolio(EurmtlReportConfig::MARKET_MAKER_ACCOUNTS, $pools);
        $market_maker['valuation'] = $this->buildMarketMakerValuation($market_maker['portfolio']);
        $totals = $this->buildTotals($accounts, $pools, $claimable_eurmtl, $asset_stats, $market_maker, $market_holders);

        return [
            'fetched_at' => time(),
            'config' => $config,
            'config_hash' => $this->configHash($config),
            'assets' => $asset_stats,
            'accounts' => array_values($accounts),
            'treasuries' => $treasuries,
            'market_holders' => $market_holders,
            'pools' => $pools,
            'market_maker' => $market_maker,
            'claimable_balances' => [
                EurmtlReportConfig::EURMTL_CODE => $claimable_eurmtl,
                EurmtlReportConfig::EURDEBT_CODE => $claimable_eurdebt,
            ],
            'unknown' => [
                'claimable_eurmtl' => $claimable_eurmtl['total'],
                'contracts_eurmtl' => $asset_stats[EurmtlReportConfig::EURMTL_CODE]['contracts_amount'],
                'pool_unclassified_eurmtl' => $totals['pool_unclassified_eurmtl'],
            ],
            'totals' => $totals,
        ];
    }

    private function buildAccounts(array $eurmtl_holders, array $eurdebt_holders, array $treasury_ids, array $pool_eurmtl_by_account): array
    {
        $account_ids = array_values(array_unique(array_merge(
            array_keys($eurmtl_holders),
            array_keys($eurdebt_holders),
            array_keys($pool_eurmtl_by_account)
        )));
        $accounts = [];

        foreach ($account_ids as $account_id) {
            $eurmtl = $eurmtl_holders[$account_id] ?? '0.0000000';
            $eurdebt = $eurdebt_holders[$account_id] ?? '0.0000000';
            $eurmtl_pool = $pool_eurmtl_by_account[$account_id] ?? '0.0000000';
            $direct_market_amount = bccomp($eurdebt, $eurmtl, self::SCALE) > 0
                ? bcsub($eurdebt, $eurmtl, self::SCALE)
                : '0.0000000';
            $eurmtl_total = bcadd($eurmtl, $eurmtl_pool, self::SCALE);
            $market_amount = bccomp($eurdebt, $eurmtl_total, self::SCALE) > 0
                ? bcsub($eurdebt, $eurmtl_total, self::SCALE)
                : '0.0000000';

            $accounts[$account_id] = [
                'account' => $this->accountData($account_id),
                'classification' => $this->classifyAccount($account_id, $treasury_ids),
                'eurmtl_direct' => $eurmtl,
                'eurmtl_pool' => $eurmtl_pool,
                'eurdebt_direct' => $eurdebt,
                'direct_market_amount' => $direct_market_amount,
                'market_amount' => $market_amount,
            ];
        }

        usort($accounts, static function (array $a, array $b): int {
            $market = bccomp($b['market_amount'], $a['market_amount'], self::SCALE);
            if ($market !== 0) {
                return $market;
            }

            return bccomp($b['eurdebt_direct'], $a['eurdebt_direct'], self::SCALE);
        });

        return $accounts;
    }

    private function collectPoolEurmtlByAccount(array $pools): array
    {
        $amounts = [];

        foreach ($pools as $pool) {
            foreach ($pool['owners'] as $owner) {
                $account_id = $owner['account']['id'] ?? null;
                if (!$account_id) {
                    continue;
                }

                $amounts[$account_id] = bcadd(
                    $amounts[$account_id] ?? '0.0000000',
                    $owner['eurmtl_amount'],
                    self::SCALE
                );
            }
        }

        return $amounts;
    }

    private function buildMarketHolders(array $eurmtl_holders, array $pool_eurmtl_by_account, array $treasury_ids, string $total_direct_eurmtl): array
    {
        $account_ids = array_values(array_unique(array_merge(
            array_keys($eurmtl_holders),
            array_keys($pool_eurmtl_by_account)
        )));
        $rows = [];
        $totals = [
            'eurmtl_direct' => '0.0000000',
            'eurmtl_pool' => '0.0000000',
            'eurmtl_total' => '0.0000000',
        ];
        $small = [
            'account' => null,
            'classification' => 'other',
            'eurmtl_direct' => '0.0000000',
            'eurmtl_pool' => '0.0000000',
            'eurmtl_total' => '0.0000000',
            'is_group' => true,
        ];
        $internal_direct = '0.0000000';
        $market_candidate_direct = '0.0000000';

        foreach ($account_ids as $account_id) {
            $classification = $this->classifyAccount($account_id, $treasury_ids);
            if (in_array($classification, ['issuer', 'legacy_treasury', 'market_maker'], true)) {
                $internal_direct = bcadd($internal_direct, $eurmtl_holders[$account_id] ?? '0.0000000', self::SCALE);
                continue;
            }

            $direct = $eurmtl_holders[$account_id] ?? '0.0000000';
            $pool = $pool_eurmtl_by_account[$account_id] ?? '0.0000000';
            $total = bcadd($direct, $pool, self::SCALE);
            if (bccomp($total, '0', self::SCALE) <= 0) {
                continue;
            }

            $row = [
                'account' => $this->accountData($account_id),
                'classification' => $classification,
                'eurmtl_direct' => $direct,
                'eurmtl_pool' => $pool,
                'eurmtl_total' => $total,
                'is_group' => false,
            ];
            $market_candidate_direct = bcadd($market_candidate_direct, $direct, self::SCALE);

            if (bccomp($total, self::MARKET_HOLDER_MIN_DISPLAY, self::SCALE) < 0) {
                $small['eurmtl_direct'] = bcadd($small['eurmtl_direct'], $direct, self::SCALE);
                $small['eurmtl_pool'] = bcadd($small['eurmtl_pool'], $pool, self::SCALE);
                $small['eurmtl_total'] = bcadd($small['eurmtl_total'], $total, self::SCALE);
                continue;
            }

            $rows[] = $row;
        }

        $market_direct_total = bcsub($total_direct_eurmtl, $internal_direct, self::SCALE);
        $small_direct_residual = bccomp($market_direct_total, $market_candidate_direct, self::SCALE) > 0
            ? bcsub($market_direct_total, $market_candidate_direct, self::SCALE)
            : '0.0000000';
        $small['eurmtl_direct'] = bcadd($small['eurmtl_direct'], $small_direct_residual, self::SCALE);
        $small['eurmtl_total'] = bcadd($small['eurmtl_total'], $small_direct_residual, self::SCALE);

        usort($rows, static fn(array $a, array $b): int => bccomp($b['eurmtl_total'], $a['eurmtl_total'], self::SCALE));

        if (bccomp($small['eurmtl_total'], '0', self::SCALE) > 0) {
            $rows[] = $small;
        }

        foreach ($rows as $row) {
            $totals['eurmtl_direct'] = bcadd($totals['eurmtl_direct'], $row['eurmtl_direct'], self::SCALE);
            $totals['eurmtl_pool'] = bcadd($totals['eurmtl_pool'], $row['eurmtl_pool'], self::SCALE);
            $totals['eurmtl_total'] = bcadd($totals['eurmtl_total'], $row['eurmtl_total'], self::SCALE);
        }

        return [
            'min_display_amount' => self::MARKET_HOLDER_MIN_DISPLAY,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    private function emptyMarketHolders(): array
    {
        return [
            'min_display_amount' => self::MARKET_HOLDER_MIN_DISPLAY,
            'rows' => [],
            'totals' => [
                'eurmtl_direct' => '0.0000000',
                'eurmtl_pool' => '0.0000000',
                'eurmtl_total' => '0.0000000',
            ],
        ];
    }

    private function collectPools(array $treasury_ids): array
    {
        $pools = [];
        $Page = $this->Stellar
            ->liquidityPools()
            ->forReserves($this->canonicalAsset(EurmtlReportConfig::EURMTL_CODE))
            ->limit(200)
            ->execute();

        while ($Page && $Page->getLiquidityPools()->count()) {
            foreach ($Page->getLiquidityPools()->toArray() as $Pool) {
                if (!$Pool instanceof LiquidityPoolResponse) {
                    continue;
                }

                $pools[] = $this->buildPool($Pool, $treasury_ids);
            }

            $Page = $Page->getNextPage();
        }

        usort($pools, static fn(array $a, array $b): int => bccomp($b['eurmtl_reserve'], $a['eurmtl_reserve'], self::SCALE));

        return $pools;
    }

    private function buildPool(LiquidityPoolResponse $Pool, array $treasury_ids): array
    {
        $reserves = [];
        $eurmtl_reserve = '0.0000000';
        $eurdebt_reserve = '0.0000000';

        foreach ($Pool->getReserves()->toArray() as $Reserve) {
            $asset = $this->assetData($Reserve->getAsset());
            $amount = $this->decimal($Reserve->getAmount());
            $reserves[] = [
                'asset' => $asset,
                'amount' => $amount,
            ];

            if ($asset['key'] === $this->assetKey(EurmtlReportConfig::EURMTL_CODE)) {
                $eurmtl_reserve = $amount;
            } elseif ($asset['key'] === $this->assetKey(EurmtlReportConfig::EURDEBT_CODE)) {
                $eurdebt_reserve = $amount;
            }
        }

        $total_shares = $this->decimal($Pool->getTotalShares());
        $owners = $this->collectPoolOwners($Pool->getPoolId(), $total_shares, $eurmtl_reserve, $treasury_ids);
        $breakdown = $this->emptyBreakdown();
        $owners_total = '0.0000000';

        foreach ($owners as $owner) {
            $classification = $owner['classification'];
            $breakdown[$classification] = bcadd($breakdown[$classification], $owner['eurmtl_amount'], self::SCALE);
            $owners_total = bcadd($owners_total, $owner['eurmtl_amount'], self::SCALE);
        }

        $unallocated = bccomp($eurmtl_reserve, $owners_total, self::SCALE) > 0
            ? bcsub($eurmtl_reserve, $owners_total, self::SCALE)
            : '0.0000000';
        $breakdown['unclassified'] = bcadd($breakdown['unclassified'], $unallocated, self::SCALE);

        $counter_asset = $this->findPoolCounterAsset($reserves);

        return [
            'id' => $Pool->getPoolId(),
            'short_id' => $this->shortHash($Pool->getPoolId()),
            'label' => $counter_asset['code'] ?? $this->shortHash($Pool->getPoolId()),
            'counter_asset' => $counter_asset,
            'stellarx_url' => $this->buildStellarxPoolUrl($reserves),
            'fee_bp' => $Pool->getFee(),
            'total_shares' => $total_shares,
            'total_trustlines' => $Pool->getTotalTrustlines(),
            'last_modified_ledger' => $Pool->getLastModifiedLedger(),
            'last_modified_time' => $Pool->getLastModifiedTime(),
            'reserves' => $reserves,
            'eurmtl_reserve' => $eurmtl_reserve,
            'eurdebt_reserve' => $eurdebt_reserve,
            'owners' => $owners,
            'breakdown' => $breakdown,
            'unallocated_eurmtl' => $unallocated,
        ];
    }

    private function collectPoolOwners(string $pool_id, string $total_shares, string $eurmtl_reserve, array $treasury_ids): array
    {
        $owners = [];
        if (bccomp($total_shares, '0', self::SCALE) <= 0) {
            return $owners;
        }

        $Page = $this->Stellar
            ->accounts()
            ->forLiquidityPool($pool_id)
            ->limit(200)
            ->execute();

        while ($Page && $Page->getAccounts()->count()) {
            foreach ($Page->getAccounts()->toArray() as $Account) {
                if (!$Account instanceof AccountResponse) {
                    continue;
                }

                $shares = $this->extractPoolShares($Account, $pool_id);
                if (bccomp($shares, '0', self::SCALE) <= 0) {
                    continue;
                }

                $ratio = bcdiv($shares, $total_shares, self::RATIO_SCALE);
                $eurmtl_amount = bcmul($eurmtl_reserve, $ratio, self::SCALE);
                $owners[] = [
                    'account' => $this->accountData($Account->getAccountId()),
                    'classification' => $this->classifyAccount($Account->getAccountId(), $treasury_ids),
                    'shares' => $shares,
                    'share_ratio' => $ratio,
                    'eurmtl_amount' => $eurmtl_amount,
                ];
            }

            $Page = $Page->getNextPage();
        }

        usort($owners, static fn(array $a, array $b): int => bccomp($b['eurmtl_amount'], $a['eurmtl_amount'], self::SCALE));

        return $owners;
    }

    private function buildMarketMakerBlock(array $accounts, array $pools): array
    {
        $account_ids = EurmtlReportConfig::MARKET_MAKER_ACCOUNTS;
        $account_id_map = array_fill_keys($account_ids, true);
        $account_rows = [];

        foreach ($accounts as $account) {
            $account_id = $account['account']['id'] ?? null;
            if ($account_id && isset($account_id_map[$account_id])) {
                $account_rows[$account_id] = $account;
            }
        }

        foreach ($account_ids as $account_id) {
            if (!isset($account_rows[$account_id])) {
                $account_rows[$account_id] = [
                    'account' => $this->accountData($account_id),
                    'classification' => 'market_maker',
                    'eurmtl_direct' => '0.0000000',
                    'eurmtl_pool' => '0.0000000',
                    'eurdebt_direct' => '0.0000000',
                    'direct_market_amount' => '0.0000000',
                    'market_amount' => '0.0000000',
                ];
            }
        }

        $totals = [
            'account' => $this->accountData(EurmtlReportConfig::MARKET_MAKER),
            'accounts' => array_map(static fn(array $account): array => $account['account'], array_values($account_rows)),
            'classification' => 'market_maker',
            'eurmtl_direct' => '0.0000000',
            'eurmtl_pool' => '0.0000000',
            'eurdebt_direct' => '0.0000000',
            'direct_market_amount' => '0.0000000',
            'market_amount' => '0.0000000',
        ];

        foreach ($account_rows as $account) {
            $totals['eurmtl_direct'] = bcadd($totals['eurmtl_direct'], $account['eurmtl_direct'], self::SCALE);
            $totals['eurmtl_pool'] = bcadd($totals['eurmtl_pool'], $account['eurmtl_pool'], self::SCALE);
            $totals['eurdebt_direct'] = bcadd($totals['eurdebt_direct'], $account['eurdebt_direct'], self::SCALE);
        }

        $totals['direct_market_amount'] = bccomp($totals['eurdebt_direct'], $totals['eurmtl_direct'], self::SCALE) > 0
            ? bcsub($totals['eurdebt_direct'], $totals['eurmtl_direct'], self::SCALE)
            : '0.0000000';
        $eurmtl_total = bcadd($totals['eurmtl_direct'], $totals['eurmtl_pool'], self::SCALE);
        $totals['market_amount'] = bccomp($totals['eurdebt_direct'], $eurmtl_total, self::SCALE) > 0
            ? bcsub($totals['eurdebt_direct'], $eurmtl_total, self::SCALE)
            : '0.0000000';

        $pool_positions = [];
        foreach ($pools as $pool) {
            foreach ($pool['owners'] as $owner) {
                if (isset($account_id_map[$owner['account']['id'] ?? ''])) {
                    $pool_positions[] = [
                        'pool_id' => $pool['id'],
                        'pool_short_id' => $pool['short_id'],
                        'pool_label' => $pool['label'],
                        'account' => $owner['account'],
                        'counter_asset' => $pool['counter_asset'],
                        'stellarx_url' => $pool['stellarx_url'],
                        'shares' => $owner['shares'],
                        'share_ratio' => $owner['share_ratio'],
                        'eurmtl_amount' => $owner['eurmtl_amount'],
                    ];
                }
            }
        }

        return [
            'account' => $this->accountData(EurmtlReportConfig::MARKET_MAKER),
            'accounts' => array_values($account_rows),
            'direct' => $totals,
            'pool_positions' => $pool_positions,
            'offers' => $this->collectOffers(EurmtlReportConfig::MARKET_MAKER),
        ];
    }

    private function collectMarketMakerPortfolio(array $account_ids, array $eurmtl_pools): array
    {
        $assets = [];
        $known_pools = [];
        foreach ($eurmtl_pools as $pool) {
            $known_pools[$pool['id']] = [
                'id' => $pool['id'],
                'total_shares' => $pool['total_shares'],
                'reserves' => $pool['reserves'],
            ];
        }

        foreach ($account_ids as $account_id) {
            try {
                $Account = $this->Stellar->requestAccount($account_id);
            } catch (HorizonRequestException|Throwable) {
                continue;
            }

            foreach ($Account->getBalances()->toArray() as $Balance) {
                if (!$Balance instanceof AccountBalanceResponse) {
                    continue;
                }

                $pool_id = $Balance->getLiquidityPoolId();
                if ($pool_id) {
                    $shares = $this->decimal($Balance->getBalance());
                    if (bccomp($shares, '0', self::SCALE) <= 0) {
                        continue;
                    }

                    $pool = $known_pools[$pool_id] ?? $this->fetchPortfolioPool($pool_id);
                    if (!$pool) {
                        continue;
                    }
                    $known_pools[$pool_id] = $pool;
                    if (bccomp($pool['total_shares'], '0', self::SCALE) <= 0) {
                        continue;
                    }

                    $ratio = bcdiv($shares, $pool['total_shares'], self::RATIO_SCALE);
                    foreach ($pool['reserves'] as $reserve) {
                        $this->addPortfolioAmount(
                            $assets,
                            $reserve['asset'],
                            'pool_amount',
                            bcmul($reserve['amount'], $ratio, self::SCALE)
                        );
                    }
                    continue;
                }

                $asset = $this->balanceAssetData($Balance);
                if ($asset === null) {
                    continue;
                }
                $this->addPortfolioAmount($assets, $asset, 'direct_amount', $this->decimal($Balance->getBalance()));
            }
        }

        foreach ($assets as &$asset) {
            $asset['total_amount'] = bcadd($asset['direct_amount'], $asset['pool_amount'], self::SCALE);
            $known_token = $asset['issuer']
                ? $this->TokensController->getKnownToken($asset['code'] . '-' . $asset['issuer'])
                : null;
            $asset['is_known'] = $asset['code'] === 'XLM' || $known_token !== null;
            $asset['title'] = $asset['issuer'] ? $asset['code'] . '-' . $asset['issuer'] : $asset['code'];
        }
        unset($asset);

        $this->TomlImageManager->applyTokenImages($assets);

        usort($assets, static function (array $a, array $b): int {
            $known = (int) ($b['is_known'] ?? false) <=> (int) ($a['is_known'] ?? false);
            if ($known !== 0) {
                return $known;
            }

            $amount = bccomp($b['total_amount'], $a['total_amount'], self::SCALE);
            if ($amount !== 0) {
                return $amount;
            }

            return strcmp($a['code'], $b['code']);
        });

        return [
            'assets' => $assets,
        ];
    }

    private function fetchPortfolioPool(string $pool_id): ?array
    {
        try {
            $Pool = $this->Stellar->liquidityPools()->forPoolId($pool_id);
        } catch (HorizonRequestException|Throwable) {
            return null;
        }

        return [
            'id' => $Pool->getPoolId(),
            'total_shares' => $this->decimal($Pool->getTotalShares()),
            'reserves' => $this->poolReservesData($Pool),
        ];
    }

    private function poolReservesData(LiquidityPoolResponse $Pool): array
    {
        $reserves = [];
        foreach ($Pool->getReserves()->toArray() as $Reserve) {
            $reserves[] = [
                'asset' => $this->assetData($Reserve->getAsset()),
                'amount' => $this->decimal($Reserve->getAmount()),
            ];
        }

        return $reserves;
    }

    private function addPortfolioAmount(array &$assets, array $asset, string $field, string $amount): void
    {
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return;
        }

        $key = $asset['key'];
        if (!isset($assets[$key])) {
            $assets[$key] = [
                'code' => $asset['code'],
                'issuer' => $asset['issuer'],
                'key' => $key,
                'direct_amount' => '0.0000000',
                'pool_amount' => '0.0000000',
                'total_amount' => '0.0000000',
                'is_known' => false,
            ];
        }

        $assets[$key][$field] = bcadd($assets[$key][$field], $amount, self::SCALE);
    }

    private function buildMarketMakerValuation(array $portfolio): array
    {
        $assets_by_code = $this->aggregatePortfolioAssetsByCode($portfolio['assets'] ?? []);
        $external_rates = $this->fetchExternalEurRates();
        $rows = [];
        $total_eur = '0.0000000';

        $configs = [
            'SATSMTL' => ['method' => 'satoshi', 'source' => 'CoinGecko BTC/EUR', 'source_url' => 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=eur'],
            'USDM' => ['method' => 'usd', 'source' => 'Frankfurter USD/EUR', 'source_url' => 'https://api.frankfurter.app/latest?from=USD&to=EUR'],
            'USDC' => ['method' => 'usd', 'source' => 'Frankfurter USD/EUR', 'source_url' => 'https://api.frankfurter.app/latest?from=USD&to=EUR'],
            'yUSDC' => ['method' => 'usd', 'source' => 'Frankfurter USD/EUR', 'source_url' => 'https://api.frankfurter.app/latest?from=USD&to=EUR'],
            'MTL' => ['method' => 'strict_send', 'source' => 'Horizon strict-send', 'source_url' => 'https://horizon.stellar.org/paths/strict-send'],
            'LABR' => ['method' => 'strict_send', 'source' => 'Horizon strict-send', 'source_url' => 'https://horizon.stellar.org/paths/strict-send'],
            'XLM' => ['method' => 'coingecko_stellar', 'source' => 'CoinGecko XLM/EUR', 'source_url' => 'https://api.coingecko.com/api/v3/simple/price?ids=stellar&vs_currencies=eur'],
            'AQUA' => ['method' => 'strict_receive', 'source' => 'Horizon strict-receive', 'source_url' => 'https://horizon.stellar.org/paths/strict-receive'],
        ];

        foreach ($configs as $code => $config) {
            $asset = $assets_by_code[$code] ?? null;
            if (!$asset || bccomp($asset['total_amount'], '1', self::SCALE) < 0) {
                continue;
            }

            $rate = $this->portfolioRateEur($asset, $config, $external_rates);
            $value_eur = $rate !== null
                ? bcmul($asset['total_amount'], $rate, self::SCALE)
                : null;
            if ($value_eur !== null) {
                $total_eur = bcadd($total_eur, $value_eur, self::SCALE);
            }

            $rows[] = [
                'asset' => $asset,
                'amount' => $asset['total_amount'],
                'rate_eur' => $rate,
                'value_eur' => $value_eur,
                'source' => $config['source'],
                'source_url' => $config['source_url'],
            ];
        }

        return [
            'rows' => $rows,
            'total_eur' => $total_eur,
            'fetched_at' => time(),
        ];
    }

    private function aggregatePortfolioAssetsByCode(array $assets): array
    {
        $result = [];
        foreach ($assets as $asset) {
            $code = (string) ($asset['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if (!isset($result[$code])) {
                $result[$code] = $asset;
                continue;
            }

            $result[$code]['direct_amount'] = bcadd($result[$code]['direct_amount'], $asset['direct_amount'], self::SCALE);
            $result[$code]['pool_amount'] = bcadd($result[$code]['pool_amount'], $asset['pool_amount'], self::SCALE);
            $result[$code]['total_amount'] = bcadd($result[$code]['total_amount'], $asset['total_amount'], self::SCALE);
            $result[$code]['is_known'] = ($result[$code]['is_known'] ?? false) || ($asset['is_known'] ?? false);
            $result[$code]['image_path'] ??= $asset['image_path'] ?? null;
        }

        return $result;
    }

    private function portfolioRateEur(array $asset, array $config, array $external_rates): ?string
    {
        return match ($config['method']) {
            'satoshi' => isset($external_rates['bitcoin_eur'])
                ? bcdiv($external_rates['bitcoin_eur'], '100000000', self::RATE_SCALE)
                : null,
            'usd' => $external_rates['usd_eur'] ?? null,
            'coingecko_stellar' => $external_rates['stellar_eur'] ?? null,
            'strict_send' => $this->fetchStrictSendEurmtlRate($asset),
            'strict_receive' => $this->fetchStrictReceiveEurmtlRate($asset),
            default => null,
        };
    }

    private function fetchExternalEurRates(): array
    {
        $rates = [];

        $coingecko = $this->fetchJson('https://api.coingecko.com/api/v3/simple/price', [
            'ids' => 'bitcoin,stellar',
            'vs_currencies' => 'eur',
        ]);
        if (isset($coingecko['bitcoin']['eur'])) {
            $rates['bitcoin_eur'] = $this->decimalRate((string) $coingecko['bitcoin']['eur']);
        }
        if (isset($coingecko['stellar']['eur'])) {
            $rates['stellar_eur'] = $this->decimalRate((string) $coingecko['stellar']['eur']);
        }

        $frankfurter = $this->fetchJson('https://api.frankfurter.app/latest', [
            'from' => 'USD',
            'to' => 'EUR',
        ]);
        if (isset($frankfurter['rates']['EUR'])) {
            $rates['usd_eur'] = $this->decimalRate((string) $frankfurter['rates']['EUR']);
        }

        return $rates;
    }

    private function fetchStrictSendEurmtlRate(array $asset): ?string
    {
        if (!$asset['issuer']) {
            return null;
        }

        $response = $this->fetchJson('https://horizon.stellar.org/paths/strict-send', [
            'source_asset_type' => $this->horizonAssetType($asset['code']),
            'source_asset_code' => $asset['code'],
            'source_asset_issuer' => $asset['issuer'],
            'source_amount' => '1',
            'destination_assets' => EurmtlReportConfig::EURMTL_CODE . ':' . EurmtlReportConfig::ISSUER,
        ]);

        $best = null;
        foreach (($response['_embedded']['records'] ?? []) as $record) {
            $amount = $record['destination_amount'] ?? null;
            if ($amount === null) {
                continue;
            }
            $amount = $this->decimalRate((string) $amount);
            if ($best === null || bccomp($amount, $best, self::RATE_SCALE) > 0) {
                $best = $amount;
            }
        }

        return $best;
    }

    private function fetchStrictReceiveEurmtlRate(array $asset): ?string
    {
        if (!$asset['issuer']) {
            return null;
        }

        $response = $this->fetchJson('https://horizon.stellar.org/paths/strict-receive', [
            'destination_asset_type' => $this->horizonAssetType(EurmtlReportConfig::EURMTL_CODE),
            'destination_asset_code' => EurmtlReportConfig::EURMTL_CODE,
            'destination_asset_issuer' => EurmtlReportConfig::ISSUER,
            'destination_amount' => '1',
            'source_assets' => $asset['code'] . ':' . $asset['issuer'],
        ]);

        $best_source = null;
        foreach (($response['_embedded']['records'] ?? []) as $record) {
            $amount = $record['source_amount'] ?? null;
            if ($amount === null) {
                continue;
            }
            $amount = $this->decimalRate((string) $amount);
            if ($best_source === null || bccomp($amount, $best_source, self::RATE_SCALE) < 0) {
                $best_source = $amount;
            }
        }

        if ($best_source === null || bccomp($best_source, '0', self::RATE_SCALE) <= 0) {
            return null;
        }

        return bcdiv('1', $best_source, self::RATE_SCALE);
    }

    private function fetchJson(string $url, array $query): ?array
    {
        try {
            $Response = $this->httpClient()->get($url, ['query' => $query]);
            if ($Response->getStatusCode() >= 400) {
                return null;
            }
            $json = json_decode((string) $Response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    private function fetchJsonUrl(string $url): ?array
    {
        try {
            $Response = $this->httpClient()->get($url);
            if ($Response->getStatusCode() >= 400) {
                return null;
            }
            $json = json_decode((string) $Response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    private function httpClient(): Client
    {
        if ($this->HttpClient === null) {
            $this->HttpClient = new Client([
                'timeout' => 6,
                'connect_timeout' => 3,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'BSN-Viewer EURMTL report',
                ],
            ]);
        }

        return $this->HttpClient;
    }

    private function horizonAssetType(string $code): string
    {
        return strlen($code) <= 4 ? 'credit_alphanum4' : 'credit_alphanum12';
    }

    private function buildTotals(array $accounts, array $pools, array $claimable_eurmtl, array $asset_stats, array $market_maker, array $market_holders): array
    {
        $legacy_market = '0.0000000';
        $direct_exposure = '0.0000000';
        foreach ($accounts as $account) {
            if (($account['classification'] ?? null) === 'legacy_treasury') {
                $legacy_market = bcadd($legacy_market, $account['market_amount'], self::SCALE);
                $direct_exposure = bcadd($direct_exposure, $account['direct_market_amount'], self::SCALE);
            }
        }
        $market_maker_market = $market_maker['direct']['market_amount'] ?? '0.0000000';
        $debt_gap_market = bcadd($legacy_market, $market_maker_market, self::SCALE);

        $pool_breakdown = $this->emptyBreakdown();
        foreach ($pools as $pool) {
            foreach ($pool['breakdown'] as $classification => $amount) {
                $pool_breakdown[$classification] = bcadd($pool_breakdown[$classification], $amount, self::SCALE);
            }
        }

        $non_issuer_pool = bcadd($pool_breakdown['other'], $pool_breakdown['unclassified'], self::SCALE);
        $market_holders_amount = $market_holders['totals']['eurmtl_total'] ?? '0.0000000';
        $claimable_amount = $claimable_eurmtl['total'] ?? '0.0000000';
        $contracts_eurmtl = $asset_stats[EurmtlReportConfig::EURMTL_CODE]['contracts_amount'] ?? '0.0000000';
        $off_account_eurmtl = bcadd($claimable_amount, $contracts_eurmtl, self::SCALE);
        $unknown = bcadd(
            bcadd($pool_breakdown['unclassified'], $claimable_amount, self::SCALE),
            $contracts_eurmtl,
            self::SCALE
        );
        $preliminary = bcadd($market_holders_amount, $off_account_eurmtl, self::SCALE);

        return [
            'legacy_treasury_market_amount' => $legacy_market,
            'market_maker_market_amount' => $market_maker_market,
            'debt_gap_market_amount' => $debt_gap_market,
            'market_holders_amount' => $market_holders_amount,
            'claimable_eurmtl' => $claimable_amount,
            'contracts_eurmtl' => $contracts_eurmtl,
            'off_account_eurmtl' => $off_account_eurmtl,
            'direct_exposure' => $direct_exposure,
            'pool_breakdown' => $pool_breakdown,
            'non_issuer_pool_eurmtl' => $non_issuer_pool,
            'issuer_owned_pool_eurmtl' => $pool_breakdown['issuer'],
            'pool_unclassified_eurmtl' => $pool_breakdown['unclassified'],
            'unknown_amount' => $unknown,
            'preliminary_market_estimate' => $preliminary,
        ];
    }

    private function collectAssetHolders(AssetTypeCreditAlphanum $asset): array
    {
        $holders = [];
        $asset_data = $this->assetData($asset);
        $Page = $this->Stellar
            ->accounts()
            ->forAsset($asset)
            ->limit(200)
            ->execute();

        while ($Page && $Page->getAccounts()->count()) {
            foreach ($Page->getAccounts()->toArray() as $Account) {
                if (!$Account instanceof AccountResponse) {
                    continue;
                }

                $balance = $this->extractAssetBalance($Account, $asset_data['code'], $asset_data['issuer']);
                if (bccomp($balance, '0', self::SCALE) > 0) {
                    $holders[$Account->getAccountId()] = $balance;
                }
            }

            $Page = $Page->getNextPage();
        }

        return $holders;
    }

    private function collectTopAssetHolders(string $code, string $min_balance): array
    {
        $holders = [];
        $asset_id = rawurlencode($code . '-' . EurmtlReportConfig::ISSUER);
        $url = 'https://api.stellar.expert/explorer/public/asset/' . $asset_id . '/holders?order=desc&limit=200';

        while ($url) {
            $response = $this->fetchJsonUrl($url);
            if (!$response) {
                break;
            }

            $records = $response['_embedded']['records'] ?? [];
            if (!$records) {
                break;
            }

            $should_stop = false;
            foreach ($records as $record) {
                $account_id = (string) ($record['account'] ?? $record['address'] ?? '');
                $balance = $this->decimalStroops($record['balance'] ?? '0');
                if (bccomp($balance, $min_balance, self::SCALE) < 0) {
                    $should_stop = true;
                    break;
                }
                if (BSN::validateStellarAccountIdFormat($account_id)) {
                    $holders[$account_id] = $balance;
                }
            }

            if ($should_stop) {
                break;
            }

            $next = (string) ($response['_links']['next']['href'] ?? '');
            $url = $next ? 'https://api.stellar.expert' . $next : null;
        }

        return $holders;
    }

    private function collectBalancesForAccounts(array $account_ids, AssetTypeCreditAlphanum $asset): array
    {
        $asset_data = $this->assetData($asset);
        $balances = [];

        foreach (array_values(array_unique($account_ids)) as $account_id) {
            try {
                $Account = $this->Stellar->requestAccount($account_id);
                $balance = $this->extractAssetBalance($Account, $asset_data['code'], $asset_data['issuer']);
            } catch (HorizonRequestException|Throwable) {
                $balance = '0.0000000';
            }

            if (bccomp($balance, '0', self::SCALE) > 0) {
                $balances[$account_id] = $balance;
            }
        }

        return $balances;
    }

    private function collectClaimableBalances(AssetTypeCreditAlphanum $asset): array
    {
        $items = [];
        $total = '0.0000000';
        $Page = $this->Stellar
            ->claimableBalances()
            ->forAsset($asset)
            ->limit(200)
            ->execute();

        while ($Page && $Page->getClaimableBalances()->count()) {
            foreach ($Page->getClaimableBalances()->toArray() as $Claimable) {
                if (!$Claimable instanceof ClaimableBalanceResponse) {
                    continue;
                }

                $amount = $this->decimal($Claimable->getAmount());
                $total = bcadd($total, $amount, self::SCALE);
                $items[] = [
                    'id' => $Claimable->getBalanceId(),
                    'short_id' => $this->shortHash($Claimable->getBalanceId()),
                    'amount' => $amount,
                    'sponsor' => $this->accountData($Claimable->getSponsor()),
                    'claimants' => array_map(
                        fn($Claimant): array => $this->accountData($Claimant->getDestination()),
                        $Claimable->getClaimants()->toArray()
                    ),
                    'last_modified_ledger' => $Claimable->getLastModifiedLedger(),
                    'last_modified_time' => $Claimable->getLastModifiedTime(),
                ];
            }

            $Page = $Page->getNextPage();
        }

        return [
            'total' => $total,
            'count' => count($items),
            'items' => $items,
        ];
    }

    private function collectOffers(string $account_id): array
    {
        $items = [];
        $Page = $this->Stellar
            ->offers()
            ->forAccount($account_id)
            ->limit(200)
            ->execute();

        while ($Page && $Page->getOffers()->count()) {
            foreach ($Page->getOffers()->toArray() as $Offer) {
                if (!$Offer instanceof OfferResponse) {
                    continue;
                }

                $items[] = [
                    'id' => $Offer->getOfferId(),
                    'selling' => $this->assetData($Offer->getSelling()),
                    'buying' => $this->assetData($Offer->getBuying()),
                    'amount' => $this->decimal($Offer->getAmount()),
                    'price' => $Offer->getPrice(),
                ];
            }

            $Page = $Page->getNextPage();
        }

        return $items;
    }

    private function fetchAssetStats(string $code): array
    {
        $Page = $this->Stellar
            ->assets()
            ->forAssetCode($code)
            ->forAssetIssuer(EurmtlReportConfig::ISSUER)
            ->limit(1)
            ->execute();

        $Asset = $Page->getAssets()->toArray()[0] ?? null;
        if (!$Asset instanceof AssetResponse) {
            return $this->emptyAssetStats($code);
        }

        $balances = $Asset->getBalances();
        $direct = bcadd(
            bcadd($balances->getAuthorized(), $balances->getAuthorizedToMaintainLiabilities(), self::SCALE),
            $balances->getUnauthorized(),
            self::SCALE
        );

        return [
            'code' => $code,
            'issuer' => EurmtlReportConfig::ISSUER,
            'direct_balances' => $this->decimal($direct),
            'authorized' => $this->decimal($balances->getAuthorized()),
            'authorized_to_maintain_liabilities' => $this->decimal($balances->getAuthorizedToMaintainLiabilities()),
            'unauthorized' => $this->decimal($balances->getUnauthorized()),
            'liquidity_pools_amount' => $this->decimal($Asset->getLiquidityPoolsAmount()),
            'claimable_balances_amount' => $this->decimal($Asset->getClaimableBalancesAmount()),
            'contracts_amount' => $this->decimal($Asset->getContractsAmount() ?? '0'),
            'archived_contracts_amount' => $this->decimal($Asset->getArchivedContractsAmount() ?? '0'),
            'num_liquidity_pools' => $Asset->getNumLiquidityPools(),
            'num_claimable_balances' => $Asset->getNumClaimableBalances(),
            'num_contracts' => $Asset->getNumContracts() ?? 0,
            'num_archived_contracts' => $Asset->getNumArchivedContracts() ?? 0,
        ];
    }

    private function emptyAssetStats(string $code): array
    {
        return [
            'code' => $code,
            'issuer' => EurmtlReportConfig::ISSUER,
            'direct_balances' => '0.0000000',
            'authorized' => '0.0000000',
            'authorized_to_maintain_liabilities' => '0.0000000',
            'unauthorized' => '0.0000000',
            'liquidity_pools_amount' => '0.0000000',
            'claimable_balances_amount' => '0.0000000',
            'contracts_amount' => '0.0000000',
            'archived_contracts_amount' => '0.0000000',
            'num_liquidity_pools' => 0,
            'num_claimable_balances' => 0,
            'num_contracts' => 0,
            'num_archived_contracts' => 0,
        ];
    }

    private function extractAssetBalance(AccountResponse $Account, string $code, string $issuer): string
    {
        foreach ($Account->getBalances()->toArray() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse) {
                continue;
            }
            if ($Balance->getAssetCode() === $code && $Balance->getAssetIssuer() === $issuer) {
                return $this->decimal($Balance->getBalance());
            }
        }

        return '0.0000000';
    }

    private function balanceAssetData(AccountBalanceResponse $Balance): ?array
    {
        if ($Balance->getAssetType() === Asset::TYPE_NATIVE) {
            return [
                'code' => 'XLM',
                'issuer' => null,
                'key' => 'XLM',
                'label' => 'XLM',
            ];
        }

        $code = $Balance->getAssetCode();
        $issuer = $Balance->getAssetIssuer();
        if (!$code || !$issuer) {
            return null;
        }

        return [
            'code' => $code,
            'issuer' => $issuer,
            'key' => $this->assetKey($code, $issuer),
            'label' => $code,
        ];
    }

    private function extractPoolShares(AccountResponse $Account, string $pool_id): string
    {
        foreach ($Account->getBalances()->toArray() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse) {
                continue;
            }
            if ($Balance->getLiquidityPoolId() === $pool_id) {
                return $this->decimal($Balance->getBalance());
            }
        }

        return '0.0000000';
    }

    private function classifyAccount(string $account_id, array $treasury_ids): string
    {
        if ($account_id === EurmtlReportConfig::ISSUER) {
            return 'issuer';
        }
        if (in_array($account_id, EurmtlReportConfig::MARKET_MAKER_ACCOUNTS, true)) {
            return 'market_maker';
        }
        if (isset($treasury_ids[$account_id])) {
            return 'legacy_treasury';
        }
        if (isset($this->known_account_ids[$account_id])) {
            return 'other';
        }

        return 'unclassified';
    }

    private function accountData(string $account_id): array
    {
        return $this->BSN->makeAccountById($account_id)->jsonSerialize();
    }

    private function assetData(Asset $Asset): array
    {
        if ($Asset instanceof AssetTypeCreditAlphanum) {
            return [
                'code' => $Asset->getCode(),
                'issuer' => $Asset->getIssuer(),
                'key' => $this->assetKey($Asset->getCode(), $Asset->getIssuer()),
                'label' => $Asset->getCode(),
            ];
        }

        return [
            'code' => 'XLM',
            'issuer' => null,
            'key' => 'XLM',
            'label' => 'XLM',
        ];
    }

    private function buildStellarxPoolUrl(array $reserves): ?string
    {
        if (count($reserves) !== 2) {
            return null;
        }

        $asset_a = $this->stellarxAssetPath($reserves[0]['asset'] ?? []);
        $asset_b = $this->stellarxAssetPath($reserves[1]['asset'] ?? []);
        if ($asset_a === null || $asset_b === null) {
            return null;
        }

        return 'https://www.stellarx.com/markets/' . $asset_a . '/' . $asset_b;
    }

    private function findPoolCounterAsset(array $reserves): ?array
    {
        $eurmtl_key = $this->assetKey(EurmtlReportConfig::EURMTL_CODE);
        foreach ($reserves as $reserve) {
            $asset = $reserve['asset'] ?? null;
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['key'] ?? null) !== $eurmtl_key) {
                return $asset;
            }
        }

        return null;
    }

    private function stellarxAssetPath(array $asset): ?string
    {
        $code = $asset['code'] ?? null;
        $issuer = $asset['issuer'] ?? null;
        if (!is_string($code) || $code === '') {
            return null;
        }

        if ($code === 'XLM' && !$issuer) {
            return 'XLM:native';
        }

        if (!is_string($issuer) || $issuer === '') {
            return null;
        }

        return rawurlencode($code) . ':' . rawurlencode($issuer);
    }

    private function makeAsset(string $code): AssetTypeCreditAlphanum
    {
        return Asset::createNonNativeAsset($code, EurmtlReportConfig::ISSUER);
    }

    private function canonicalAsset(string $code): string
    {
        return $code . ':' . EurmtlReportConfig::ISSUER;
    }

    private function assetKey(string $code, ?string $issuer = EurmtlReportConfig::ISSUER): string
    {
        return $issuer ? $code . '-' . $issuer : $code;
    }

    private function emptyBreakdown(): array
    {
        return [
            'issuer' => '0.0000000',
            'legacy_treasury' => '0.0000000',
            'market_maker' => '0.0000000',
            'other' => '0.0000000',
            'unclassified' => '0.0000000',
        ];
    }

    private function decimal(null|int|float|string $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '') {
            $value = '0';
        }

        return bcadd($value, '0', self::SCALE);
    }

    private function decimalRate(null|int|float|string $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '') {
            $value = '0';
        }

        return bcadd($value, '0', self::RATE_SCALE);
    }

    private function decimalStroops(null|int|float|string $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '') {
            $value = '0';
        }

        return bcdiv($value, '10000000', self::SCALE);
    }

    private function shortHash(string $value): string
    {
        return substr($value, 0, 6) . '...' . substr($value, -6);
    }

    private function finalizeSnapshot(array $snapshot): array
    {
        $fetched_at = isset($snapshot['fetched_at']) ? (int) $snapshot['fetched_at'] : 0;
        $age = $fetched_at > 0 ? max(0, time() - $fetched_at) : null;
        $snapshot['age_seconds'] = $age;
        $snapshot['is_fresh'] = $age !== null && $age < self::FRESH_SNAPSHOT_SECONDS;
        $snapshot['is_stale_cache'] = $age !== null && $age >= self::STALE_CACHE_SECONDS;

        return $snapshot;
    }

    private function makeSnapshotCacheKey(array $config): string
    {
        return self::CACHE_KEY_PREFIX . ':' . $this->configHash($config);
    }

    private function configHash(array $config): string
    {
        return sha1(json_encode($config, JSON_THROW_ON_ERROR));
    }

    private function cacheFetch(string $key): mixed
    {
        $entry = $this->CacheManager->fetch($key);
        return $entry['data'] ?? null;
    }

    private function cacheStore(string $key, mixed $value, int $ttl): void
    {
        $this->CacheManager->store($key, $value, $ttl, [
            'scope' => 'eurmtl_report_snapshot',
        ]);
    }
}
