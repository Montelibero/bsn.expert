<?php

namespace Montelibero\BSN\Controllers;

use DateTimeImmutable;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Operations\OperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PaymentOperationResponse;
use Soneso\StellarSDK\StellarSDK;
use Throwable;
use Twig\Environment;

class MtlaRpReportController
{
    private const MTLA_ACCOUNT = MtlaController::MTLA_ACCOUNT;
    private const CACHE_KEY_PREFIX = 'mtla_rp_report_snapshot:v2';
    private const CACHE_TTL = 86400;
    private const LOOKBACK_DAYS = 90;
    private const ACTIVIST_MIN_MTLAP = 4;
    private const MIN_REQUIRED_TT = 12.0;
    private const FRESH_SNAPSHOT_SECONDS = 60;

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private CurrentUser $CurrentUser;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, CurrentUser $CurrentUser)
    {
        $this->BSN = $BSN;
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        $this->Stellar = $Stellar;
        $this->CurrentUser = $CurrentUser;
    }

    public function MtlaRpReport(): ?string
    {
        $can_refresh = $this->canRefreshSnapshot();
        $refresh_code = $can_refresh ? $this->buildRefreshCode() : null;
        $force_refresh = $can_refresh
            && (string) ($_GET['refresh'] ?? '') === '1'
            && $refresh_code !== null
            && hash_equals($refresh_code, (string) ($_GET['code'] ?? ''));

        $programs = $this->collectPrograms();
        $snapshot = $this->fetchMtlaSnapshot(
            array_map(
                static fn(array $item): string => $item['data']['id'],
                $programs['items']
            ),
            $force_refresh
        );
        $activists = $this->collectActivists($programs['memberships'], $snapshot);

        if ($force_refresh) {
            if (($snapshot['warning'] ?? null) !== null) {
                SimpleRouter::response()->redirect('/tools/mtla/rp_report?refresh_status=fallback', 302);
            } else {
                SimpleRouter::response()->redirect('/tools/mtla/rp_report', 302);
            }
            return null;
        }

        $heroes = array_values(array_filter($activists, static fn(array $item): bool => $item['is_ideal']));

        $Template = $this->Twig->load('tools_mtla_rp_report.twig');
        return $Template->render([
            'is_wide_page' => true,
            'mtla_account' => $this->BSN->makeAccountById(self::MTLA_ACCOUNT)->jsonSerialize(),
            'summary' => [
                'activists_total' => count($activists),
                'heroes_total' => count($heroes),
                'programs_total' => count($programs['items']),
                'problem_programs_total' => count($programs['problem_items']),
            ],
            'snapshot' => $snapshot,
            'refresh' => [
                'can_refresh' => $can_refresh,
                'url' => $refresh_code
                    ? '/tools/mtla/rp_report?refresh=1&code=' . urlencode($refresh_code)
                    : null,
                'status' => (string) ($_GET['refresh_status'] ?? ''),
            ],
            'activists' => $activists,
            'heroes' => $heroes,
            'anomalies' => [
                'missing_timetoken' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_timetoken'])),
                'missing_trustline' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_trustline'])),
                'not_sending_tokens' => array_values(array_filter($activists, static fn(array $item): bool => $item['low_incoming'])),
                'tokens_not_spent' => array_values(array_filter($activists, static fn(array $item): bool => $item['low_outgoing'])),
                'without_programs' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_programs'])),
            ],
            'programs' => $programs['items'],
            'problem_programs' => $programs['problem_items'],
        ]);
    }

    private function collectPrograms(): array
    {
        $MTLA = $this->BSN->getAccountById(self::MTLA_ACCOUNT);
        $TagProgram = $this->BSN->makeTagByName('Program');
        $TagProgramCoordinator = $this->BSN->makeTagByName('ProgramCoordinator');
        $TagMyPart = $this->BSN->makeTagByName('MyPart');
        $TagPartOf = $this->BSN->makeTagByName('PartOf');

        $memberships = [];
        $items = [];

        foreach ($MTLA?->getOutcomeLinks($TagProgram) ?? [] as $Program) {
            $coordinators = $Program->getOutcomeLinks($TagProgramCoordinator);
            $outgoing_participants = $Program->getOutcomeLinks($TagMyPart);
            $confirmed_participants = [];
            $broken_outgoing = [];

            foreach ($outgoing_participants as $Participant) {
                if ($this->hasOutgoingLink($Participant, $TagPartOf, $Program)) {
                    $confirmed_participants[] = $this->buildProgramParticipant($Participant);
                    $memberships[$Participant->getId()][] = $Program->jsonSerialize();
                } else {
                    $broken_outgoing[] = $Participant->jsonSerialize();
                }
            }

            usort($confirmed_participants, fn(array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));
            usort($broken_outgoing, fn(array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

            $item = [
                'data' => $Program->jsonSerialize(),
                'coordinator' => ($coordinators[0] ?? null)?->jsonSerialize(),
                'coordinator_count' => count($coordinators),
                'participants' => $confirmed_participants,
                'broken_outgoing_participants' => $broken_outgoing,
                'issues' => [
                    'missing_coordinator' => count($coordinators) === 0,
                    'multiple_coordinators' => count($coordinators) > 1,
                    'no_participants' => count($confirmed_participants) === 0,
                    'broken_outgoing_links' => count($broken_outgoing) > 0,
                ],
            ];
            $item['has_issues'] = in_array(true, $item['issues'], true);
            $item['severity'] = $this->calculateProgramSeverity($item['issues']);
            $items[] = $item;
        }

        usort($items, function (array $a, array $b): int {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] <=> $b['severity'];
            }

            $participants_a = count($a['participants']);
            $participants_b = count($b['participants']);
            if ($participants_a !== $participants_b) {
                return $participants_b <=> $participants_a;
            }

            return strcmp($a['data']['display_name'], $b['data']['display_name']);
        });

        return [
            'items' => $items,
            'problem_items' => array_values(array_filter($items, static fn(array $item): bool => $item['has_issues'])),
            'memberships' => $memberships,
        ];
    }

    private function buildProgramParticipant(Account $Participant): array
    {
        $data = $Participant->jsonSerialize();
        $time_token = $this->resolveTimeToken($Participant);
        if ($time_token !== null) {
            $data['tt_code'] = $time_token['code'];
            $data['tt_issuer'] = $time_token['issuer'];
        }

        return $data;
    }

    private function collectActivists(array $memberships, array $snapshot): array
    {
        $items = [];

        foreach ($this->BSN->getAccounts() as $Account) {
            $mtlap = $Account->getBalance('MTLAP');
            if ($mtlap < self::ACTIVIST_MIN_MTLAP) {
                continue;
            }

            $time_token = $this->resolveTimeToken($Account);
            $tt_code = $time_token['code'] ?? null;
            $tt_issuer = $time_token['issuer'] ?? null;
            $asset_key = $time_token['asset_key'] ?? null;
            $incoming_tt = $asset_key ? (float) ($snapshot['incoming_totals'][$Account->getId()][$asset_key] ?? 0.0) : 0.0;
            $outgoing_tt = $asset_key ? (float) ($snapshot['outgoing_totals'][$asset_key] ?? 0.0) : 0.0;
            $programs = $memberships[$Account->getId()] ?? [];

            usort($programs, fn(array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

            $missing_timetoken = $asset_key === null;
            $missing_trustline = $asset_key !== null && !isset($snapshot['trustlines'][$asset_key]);
            $low_incoming = $asset_key !== null && $incoming_tt < self::MIN_REQUIRED_TT;
            $low_outgoing = $asset_key !== null && $outgoing_tt < self::MIN_REQUIRED_TT;
            $missing_programs = count($programs) === 0;

            $issues = [];
            if ($missing_timetoken) {
                $issues[] = 'missing_timetoken';
            }
            if ($missing_trustline) {
                $issues[] = 'missing_trustline';
            }
            if ($low_incoming) {
                $issues[] = 'low_incoming';
            }
            if ($low_outgoing) {
                $issues[] = 'low_outgoing';
            }
            if ($missing_programs) {
                $issues[] = 'missing_programs';
            }

            $items[] = [
                'account' => $Account->jsonSerialize(),
                'mtlap' => $mtlap,
                'timetoken' => [
                    'code' => $tt_code,
                    'issuer' => $tt_issuer,
                    'url' => ($tt_code && $tt_issuer) ? '/tokens/' . $tt_code . '-' . $tt_issuer : null,
                ],
                'mtla_tt_balance' => $asset_key ? (float) ($snapshot['trustlines'][$asset_key]['amount'] ?? 0.0) : null,
                'incoming_tt_90d' => $incoming_tt,
                'outgoing_tt_90d' => $outgoing_tt,
                'programs' => $programs,
                'missing_timetoken' => $missing_timetoken,
                'missing_trustline' => $missing_trustline,
                'low_incoming' => $low_incoming,
                'low_outgoing' => $low_outgoing,
                'missing_programs' => $missing_programs,
                'issues' => $issues,
                'severity' => $this->calculateActivistSeverity([
                    'missing_timetoken' => $missing_timetoken,
                    'missing_trustline' => $missing_trustline,
                    'low_incoming' => $low_incoming,
                    'low_outgoing' => $low_outgoing,
                    'missing_programs' => $missing_programs,
                    'incoming_tt_90d' => $incoming_tt,
                    'outgoing_tt_90d' => $outgoing_tt,
                ]),
                'is_ideal' => count($issues) === 0,
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] <=> $b['severity'];
            }

            if ($a['outgoing_tt_90d'] !== $b['outgoing_tt_90d']) {
                return $b['outgoing_tt_90d'] <=> $a['outgoing_tt_90d'];
            }

            $program_count_a = count($a['programs']);
            $program_count_b = count($b['programs']);
            if ($program_count_a !== $program_count_b) {
                return $program_count_b <=> $program_count_a;
            }

            return strcmp($a['account']['display_name'], $b['account']['display_name']);
        });

        return $items;
    }

    private function fetchMtlaSnapshot(array $program_account_ids, bool $force_refresh): array
    {
        $cache_key = $this->makeSnapshotCacheKey($program_account_ids);
        $cached = $this->cacheFetch($cache_key);
        if (!$force_refresh && is_array($cached)) {
            $cached['from_cache'] = true;
            $cached['warning'] = null;
            return $this->finalizeSnapshot($cached);
        }

        try {
            $snapshot = $this->buildMtlaSnapshot(
                $this->Stellar->requestAccount(self::MTLA_ACCOUNT),
                $program_account_ids
            );
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
                'cutoff_at' => time() - (self::LOOKBACK_DAYS * 86400),
                'balances' => [],
                'trustlines' => [],
                'incoming_totals' => [],
                'outgoing_totals' => [],
                'payments_count' => 0,
                'from_cache' => false,
                'warning' => $Exception->getMessage(),
            ]);
        }
    }

    private function finalizeSnapshot(array $snapshot): array
    {
        $fetched_at = isset($snapshot['fetched_at']) ? (int) $snapshot['fetched_at'] : 0;
        $snapshot['is_fresh'] = $fetched_at > 0 && (time() - $fetched_at) < self::FRESH_SNAPSHOT_SECONDS;

        return $snapshot;
    }

    private function buildMtlaSnapshot(AccountResponse $MtlaAccount, array $program_account_ids): array
    {
        $balances = [];
        $trustlines = [];

        foreach ($MtlaAccount->getBalances()->toArray() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse) {
                continue;
            }

            $code = $Balance->getAssetType() === Asset::TYPE_NATIVE ? 'XLM' : $Balance->getAssetCode();
            $issuer = $Balance->getAssetType() === Asset::TYPE_NATIVE ? null : $Balance->getAssetIssuer();
            $balance = [
                'code' => $code,
                'issuer' => $issuer,
                'amount' => (float) $Balance->getBalance(),
            ];
            $balances[] = $balance;

            $asset_key = $this->makeAssetKey($code, $issuer);
            if ($asset_key !== null) {
                $trustlines[$asset_key] = $balance;
            }
        }

        $totals = $this->collectRecentMtlaTotals($program_account_ids);

        return [
            'fetched_at' => time(),
            'cutoff_at' => $totals['cutoff_at'],
            'balances' => $balances,
            'trustlines' => $trustlines,
            'incoming_totals' => $totals['incoming_totals'],
            'outgoing_totals' => $totals['outgoing_totals'],
            'payments_count' => $totals['payments_count'],
        ];
    }

    private function collectRecentMtlaTotals(array $program_account_ids): array
    {
        $cutoff_at = time() - (self::LOOKBACK_DAYS * 86400);
        $incoming_totals = [];
        $outgoing_totals = [];
        $payments_count = 0;

        $Payments = $this->Stellar
            ->payments()
            ->forAccount(self::MTLA_ACCOUNT)
            ->order('desc')
            ->limit(200)
            ->execute();

        while ($Payments && $Payments->getOperations()->count()) {
            $stop = false;
            foreach ($Payments->getOperations()->toArray() as $Operation) {
                if (!$Operation instanceof OperationResponse) {
                    continue;
                }

                $created_at = $this->parseTimestamp($Operation->getCreatedAt());
                if ($created_at !== null && $created_at < $cutoff_at) {
                    $stop = true;
                    break;
                }

                $payment = $this->normalizeMtlaPayment($Operation);
                if ($payment === null) {
                    continue;
                }

                $payments_count++;
                if (!isset($incoming_totals[$payment['account_id']])) {
                    $incoming_totals[$payment['account_id']] = [];
                }
                if (!isset($incoming_totals[$payment['account_id']][$payment['asset_key']])) {
                    $incoming_totals[$payment['account_id']][$payment['asset_key']] = .0;
                }
                $incoming_totals[$payment['account_id']][$payment['asset_key']] += $payment['amount'];
            }

            if ($stop) {
                break;
            }

            $Payments = $Payments->getNextPage();
        }

        foreach (array_values(array_unique($program_account_ids)) as $program_account_id) {
            $Payments = $this->Stellar
                ->payments()
                ->forAccount($program_account_id)
                ->order('desc')
                ->limit(200)
                ->execute();

            while ($Payments && $Payments->getOperations()->count()) {
                $stop = false;
                foreach ($Payments->getOperations()->toArray() as $Operation) {
                    if (!$Operation instanceof OperationResponse) {
                        continue;
                    }

                    $created_at = $this->parseTimestamp($Operation->getCreatedAt());
                    if ($created_at !== null && $created_at < $cutoff_at) {
                        $stop = true;
                        break;
                    }

                    $payment = $this->normalizeProgramOutgoingPayment($program_account_id, $Operation);
                    if ($payment === null) {
                        continue;
                    }

                    $payments_count++;
                    if (!isset($outgoing_totals[$payment['asset_key']])) {
                        $outgoing_totals[$payment['asset_key']] = .0;
                    }
                    $outgoing_totals[$payment['asset_key']] += $payment['amount'];
                }

                if ($stop) {
                    break;
                }

                $Payments = $Payments->getNextPage();
            }
        }

        return [
            'cutoff_at' => $cutoff_at,
            'incoming_totals' => $incoming_totals,
            'outgoing_totals' => $outgoing_totals,
            'payments_count' => $payments_count,
        ];
    }

    private function normalizeMtlaPayment(OperationResponse $Operation): ?array
    {
        if ($Operation instanceof PaymentOperationResponse) {
            $asset = $this->assetParts($Operation->getAsset());
            $amount = (float) $Operation->getAmount();
            $from = $Operation->getFrom();
            $to = $Operation->getTo();
        } elseif ($Operation instanceof PathPaymentOperationResponse) {
            if ($Operation->getFrom() === self::MTLA_ACCOUNT) {
                $asset = $this->assetParts($Operation->getSourceAsset());
                $amount = (float) $Operation->getSourceAmount();
                $from = $Operation->getFrom();
                $to = $Operation->getTo();
            } elseif ($Operation->getTo() === self::MTLA_ACCOUNT) {
                $asset = $this->assetParts($Operation->getAsset());
                $amount = (float) $Operation->getAmount();
                $from = $Operation->getFrom();
                $to = $Operation->getTo();
            } else {
                return null;
            }
        } else {
            return null;
        }

        $asset_key = $this->makeAssetKey($asset['code'], $asset['issuer']);
        if ($asset_key === null) {
            return null;
        }

        if ($to === self::MTLA_ACCOUNT) {
            return [
                'account_id' => $from,
                'asset_key' => $asset_key,
                'amount' => $amount,
            ];
        }

        return null;
    }

    private function normalizeProgramOutgoingPayment(string $program_account_id, OperationResponse $Operation): ?array
    {
        if ($Operation instanceof PaymentOperationResponse) {
            $asset = $this->assetParts($Operation->getAsset());
            $amount = (float) $Operation->getAmount();
            $from = $Operation->getFrom();
            $to = $Operation->getTo();
        } elseif ($Operation instanceof PathPaymentOperationResponse) {
            if ($Operation->getFrom() !== $program_account_id) {
                return null;
            }

            $asset = $this->assetParts($Operation->getSourceAsset());
            $amount = (float) $Operation->getSourceAmount();
            $from = $Operation->getFrom();
            $to = $Operation->getTo();
        } else {
            return null;
        }

        if ($from !== $program_account_id) {
            return null;
        }

        $asset_key = $this->makeAssetKey($asset['code'], $asset['issuer']);
        if ($asset_key === null || $to !== $asset['issuer']) {
            return null;
        }

        return [
            'asset_key' => $asset_key,
            'amount' => $amount,
        ];
    }

    private function assetParts(Asset $Asset): array
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            return [
                'code' => 'XLM',
                'issuer' => null,
            ];
        }

        return [
            'code' => $Asset->getCode(),
            'issuer' => $Asset->getIssuer(),
        ];
    }

    private function hasOutgoingLink(Account $Source, $Tag, Account $Target): bool
    {
        foreach ($Source->getOutcomeLinks($Tag) as $Linked) {
            if ($Linked->getId() === $Target->getId()) {
                return true;
            }
        }

        return false;
    }

    private function calculateProgramSeverity(array $issues): int
    {
        if (
            !empty($issues['missing_coordinator'])
            || !empty($issues['no_participants'])
        ) {
            return 2;
        }

        if (
            !empty($issues['multiple_coordinators'])
            || !empty($issues['broken_outgoing_links'])
        ) {
            return 1;
        }

        return 0;
    }

    private function calculateActivistSeverity(array $state): int
    {
        if (
            !empty($state['missing_timetoken'])
            || !empty($state['missing_programs'])
            || (!empty($state['low_incoming']) && (float) ($state['incoming_tt_90d'] ?? 0.0) <= 0.0)
            || (!empty($state['low_outgoing']) && (float) ($state['outgoing_tt_90d'] ?? 0.0) <= 0.0)
        ) {
            return 2;
        }

        if (
            !empty($state['missing_trustline'])
            || !empty($state['low_incoming'])
            || !empty($state['low_outgoing'])
        ) {
            return 1;
        }

        return 0;
    }

    private function resolveTimeToken(Account $Account): ?array
    {
        $code = trim((string) $Account->getProfileSingleItem('TimeTokenCode'));
        if (!BSN::validateTokenNameFormat($code)) {
            return null;
        }

        $issuer = null;
        $TagTimeTokenIssuer = $this->BSN->makeTagByName('TimeTokenIssuer');
        if ($tt_issuers = $Account->getOutcomeLinks($TagTimeTokenIssuer)) {
            $issuer = $tt_issuers[0]->getId();
        } else {
            foreach (['TimeTokenIssuer', 'TimeTockenIssuer'] as $field_name) {
                $profile_issuer = trim((string) $Account->getProfileSingleItem($field_name));
                if ($profile_issuer !== '') {
                    $issuer = $profile_issuer;
                    break;
                }
            }
        }

        if (!$issuer) {
            $issuer = $Account->getId();
        }

        if (!BSN::validateStellarAccountIdFormat($issuer)) {
            $issuer = $Account->getId();
        }

        return [
            'code' => $code,
            'issuer' => $issuer,
            'asset_key' => $this->makeAssetKey($code, $issuer),
        ];
    }

    private function canRefreshSnapshot(): bool
    {
        $Account = $this->CurrentUser->getAccount();
        return $Account !== null && $Account->getBalance('MTLAP') >= self::ACTIVIST_MIN_MTLAP;
    }

    private function buildRefreshCode(): string
    {
        return hash('sha256', session_id() . ':mtla_rp_report_refresh');
    }

    private function makeSnapshotCacheKey(array $program_account_ids): string
    {
        $program_account_ids = array_values(array_unique($program_account_ids));
        sort($program_account_ids);

        return self::CACHE_KEY_PREFIX . ':' . sha1(implode(',', $program_account_ids));
    }

    private function makeAssetKey(?string $code, ?string $issuer): ?string
    {
        if (!$code || !$issuer || $code === 'XLM') {
            return null;
        }

        return $code . '-' . $issuer;
    }

    private function parseTimestamp(string $value): ?int
    {
        $Date = new DateTimeImmutable($value);
        return $Date->getTimestamp();
    }

    private function cacheFetch(string $key): mixed
    {
        if (!function_exists('apcu_fetch')) {
            return null;
        }

        $success = false;
        $value = apcu_fetch($key, $success);
        return $success ? $value : null;
    }

    private function cacheStore(string $key, mixed $value, int $ttl): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }

        apcu_store($key, $value, $ttl);
    }
}
