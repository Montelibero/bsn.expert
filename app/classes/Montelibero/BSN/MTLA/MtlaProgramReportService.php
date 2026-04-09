<?php

namespace Montelibero\BSN\MTLA;

use DateTimeImmutable;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\MongoCacheManager;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Operations\OperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PaymentOperationResponse;
use Soneso\StellarSDK\StellarSDK;
use Throwable;

class MtlaProgramReportService
{
    private const MTLA_ACCOUNT = MtlaController::MTLA_ACCOUNT;
    private const CACHE_KEY_PREFIX = 'mtla_rp_report_snapshot:v4';
    private const CACHE_TTL = 604800;
    private const LOOKBACK_DAYS = 90;
    private const ACTIVIST_MIN_MTLAP = 4;
    private const MIN_REQUIRED_TT = 12.0;
    private const FRESH_SNAPSHOT_SECONDS = 60;
    private const STALE_CACHE_SECONDS = 86400;

    private BSN $BSN;
    private StellarSDK $Stellar;
    private CurrentUser $CurrentUser;
    private MongoCacheManager $CacheManager;

    public function __construct(BSN $BSN, StellarSDK $Stellar, CurrentUser $CurrentUser, MongoCacheManager $CacheManager)
    {
        $this->BSN = $BSN;
        $this->Stellar = $Stellar;
        $this->CurrentUser = $CurrentUser;
        $this->CacheManager = $CacheManager;
    }

    public function getMtlaAccountData(): array
    {
        return $this->BSN->makeAccountById(self::MTLA_ACCOUNT)->jsonSerialize();
    }

    public function collectPrograms(): array
    {
        $MTLA = $this->BSN->getAccountById(self::MTLA_ACCOUNT);
        $TagProgram = $this->BSN->makeTagByName('Program');
        $TagProgramCoordinator = $this->BSN->makeTagByName('ProgramCoordinator');
        $TagMyPart = $this->BSN->makeTagByName('MyPart');
        $TagPartOf = $this->BSN->makeTagByName('PartOf');

        $memberships = [];
        $mentioned_account_ids = [];
        $items = [];

        foreach ($MTLA?->getOutcomeLinks($TagProgram) ?? [] as $Program) {
            $coordinator_candidates = $Program->getOutcomeLinks($TagProgramCoordinator);
            $outgoing_participants = $Program->getOutcomeLinks($TagMyPart);
            $confirmed_participants = [];
            $broken_outgoing = [];

            $mentioned_account_ids[$Program->getId()] = true;

            foreach ($coordinator_candidates as $Coordinator) {
                $mentioned_account_ids[$Coordinator->getId()] = true;
            }

            foreach ($outgoing_participants as $Participant) {
                if (!$this->isEligibleProgramParticipant($Participant)) {
                    continue;
                }

                if ($this->hasOutgoingLink($Participant, $TagPartOf, $Program)) {
                    $confirmed_participants[] = $this->buildProgramParticipant($Participant);
                    $memberships[$Participant->getId()][] = $Program->jsonSerialize();
                    $mentioned_account_ids[$Participant->getId()] = true;
                } else {
                    $broken_outgoing[] = $Participant->jsonSerialize();
                }
            }

            usort($confirmed_participants, fn(array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));
            usort($broken_outgoing, fn(array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

            $confirmed_participant_ids = array_fill_keys(
                array_map(static fn(array $participant): string => $participant['id'], $confirmed_participants),
                true
            );
            $coordinators = array_values(array_filter(
                $coordinator_candidates,
                static fn(Account $Coordinator): bool => isset($confirmed_participant_ids[$Coordinator->getId()])
            ));

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
            'mentioned_account_ids' => array_keys($mentioned_account_ids),
        ];
    }

    public function findProgramByAccountId(array $programs, string $account_id): ?array
    {
        foreach ($programs as $program) {
            if (($program['data']['id'] ?? null) === $account_id) {
                return $program;
            }
        }

        return null;
    }

    public function collectActivists(array $memberships, array $snapshot): array
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

    public function buildProgramParticipantReport(array $program, array $snapshot): array
    {
        $program_id = $program['data']['id'];
        $items = [];

        foreach ($program['participants'] as $participant_data) {
            $Participant = $this->BSN->makeAccountById($participant_data['id']);
            $time_token = $this->resolveTimeToken($Participant);
            $asset_key = $time_token['asset_key'] ?? null;
            $incoming_tt = $asset_key ? (float) ($snapshot['incoming_totals'][$Participant->getId()][$asset_key] ?? 0.0) : 0.0;
            $program_outgoing_tt = $asset_key ? (float) ($snapshot['program_outgoing_totals'][$program_id][$asset_key] ?? 0.0) : 0.0;
            $has_program_trustline = $asset_key !== null && isset($snapshot['program_trustlines'][$program_id][$asset_key]);
            $status = $this->buildProgramParticipantStatus($asset_key, $has_program_trustline, $incoming_tt, $program_outgoing_tt);

            $items[] = [
                'account' => $Participant->jsonSerialize(),
                'timetoken' => [
                    'code' => $time_token['code'] ?? null,
                    'issuer' => $time_token['issuer'] ?? null,
                    'url' => isset($time_token['code'], $time_token['issuer'])
                        ? '/tokens/' . $time_token['code'] . '-' . $time_token['issuer']
                        : null,
                ],
                'mtla_tt_balance' => $asset_key ? (float) ($snapshot['trustlines'][$asset_key]['amount'] ?? 0.0) : null,
                'program_tt_balance' => $asset_key && $has_program_trustline
                    ? (float) ($snapshot['program_trustlines'][$program_id][$asset_key]['amount'] ?? 0.0)
                    : null,
                'incoming_tt_90d' => $incoming_tt,
                'program_outgoing_tt_90d' => $program_outgoing_tt,
                'status' => $status,
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['status']['sort_order'] !== $b['status']['sort_order']) {
                return $a['status']['sort_order'] <=> $b['status']['sort_order'];
            }

            if ($a['incoming_tt_90d'] !== $b['incoming_tt_90d']) {
                return $a['incoming_tt_90d'] <=> $b['incoming_tt_90d'];
            }

            return strcmp($a['account']['display_name'], $b['account']['display_name']);
        });

        return $items;
    }

    public function fetchMtlaSnapshot(array $program_account_ids, bool $force_refresh): array
    {
        $program_account_ids = $this->normalizeProgramAccountIds($program_account_ids);
        $cache_key = $this->makeSnapshotCacheKey($program_account_ids);
        $cached = $this->cacheFetch($cache_key);
        if (
            !$force_refresh
            && is_array($cached)
            && $this->hasMatchingProgramSet($cached, $program_account_ids)
        ) {
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
                'program_trustlines' => [],
                'program_account_warnings' => [],
                'incoming_totals' => [],
                'outgoing_totals' => [],
                'program_outgoing_totals' => [],
                'payments_count' => 0,
                'from_cache' => false,
                'warning' => $Exception->getMessage(),
            ]);
        }
    }

    public function canRefreshSnapshot(): bool
    {
        $Account = $this->CurrentUser->getAccount();
        return $Account !== null && $Account->getBalance('MTLAP') >= self::ACTIVIST_MIN_MTLAP;
    }

    public function getMinRequiredTt(): float
    {
        return self::MIN_REQUIRED_TT;
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

    private function buildMtlaSnapshot(AccountResponse $MtlaAccount, array $program_account_ids): array
    {
        $program_account_ids = $this->normalizeProgramAccountIds($program_account_ids);
        $mtla_balances = $this->extractBalances($MtlaAccount);
        $totals = $this->collectRecentMtlaTotals($program_account_ids);

        return [
            'fetched_at' => time(),
            'program_account_ids' => $program_account_ids,
            'cutoff_at' => $totals['cutoff_at'],
            'balances' => $mtla_balances['balances'],
            'trustlines' => $mtla_balances['trustlines'],
            'program_trustlines' => $totals['program_trustlines'],
            'program_account_warnings' => $totals['program_account_warnings'],
            'incoming_totals' => $totals['incoming_totals'],
            'outgoing_totals' => $totals['outgoing_totals'],
            'program_outgoing_totals' => $totals['program_outgoing_totals'],
            'payments_count' => $totals['payments_count'],
        ];
    }

    private function collectRecentMtlaTotals(array $program_account_ids): array
    {
        $cutoff_at = time() - (self::LOOKBACK_DAYS * 86400);
        $incoming_totals = [];
        $outgoing_totals = [];
        $program_outgoing_totals = [];
        $program_trustlines = [];
        $program_account_warnings = [];
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
            try {
                $ProgramAccount = $this->Stellar->requestAccount($program_account_id);
                $program_trustlines[$program_account_id] = $this->extractBalances($ProgramAccount)['trustlines'];

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
                        if (!isset($program_outgoing_totals[$program_account_id])) {
                            $program_outgoing_totals[$program_account_id] = [];
                        }
                        if (!isset($program_outgoing_totals[$program_account_id][$payment['asset_key']])) {
                            $program_outgoing_totals[$program_account_id][$payment['asset_key']] = .0;
                        }

                        $outgoing_totals[$payment['asset_key']] += $payment['amount'];
                        $program_outgoing_totals[$program_account_id][$payment['asset_key']] += $payment['amount'];
                    }

                    if ($stop) {
                        break;
                    }

                    $Payments = $Payments->getNextPage();
                }
            } catch (HorizonRequestException|Throwable $Exception) {
                $program_account_warnings[$program_account_id] = $Exception->getMessage();
            }
        }

        return [
            'cutoff_at' => $cutoff_at,
            'program_trustlines' => $program_trustlines,
            'program_account_warnings' => $program_account_warnings,
            'incoming_totals' => $incoming_totals,
            'outgoing_totals' => $outgoing_totals,
            'program_outgoing_totals' => $program_outgoing_totals,
            'payments_count' => $payments_count,
        ];
    }

    private function extractBalances(AccountResponse $Account): array
    {
        $balances = [];
        $trustlines = [];

        foreach ($Account->getBalances()->toArray() as $Balance) {
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

        return [
            'balances' => $balances,
            'trustlines' => $trustlines,
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

    private function buildProgramParticipantStatus(
        ?string $asset_key,
        bool $has_program_trustline,
        float $incoming_tt,
        float $program_outgoing_tt
    ): array {
        if ($asset_key === null) {
            return [
                'code' => 'missing_timetoken',
                'theme' => 'danger',
                'sort_order' => 1,
            ];
        }

        if (!$has_program_trustline) {
            return [
                'code' => 'missing_program_trustline',
                'theme' => 'warning',
                'sort_order' => 0,
            ];
        }

        if ($incoming_tt <= 0.0) {
            return [
                'code' => 'no_incoming',
                'theme' => 'danger',
                'sort_order' => 2,
            ];
        }

        if ($incoming_tt < self::MIN_REQUIRED_TT) {
            return [
                'code' => 'low_incoming',
                'theme' => 'warning',
                'sort_order' => 3,
            ];
        }

        if ($program_outgoing_tt <= 0.0) {
            return [
                'code' => 'no_program_outgoing',
                'theme' => 'warning',
                'sort_order' => 4,
            ];
        }

        return [
            'code' => 'ideal',
            'theme' => 'success',
            'sort_order' => 5,
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

    private function isEligibleProgramParticipant(Account $Account): bool
    {
        return $Account->getBalance('MTLAP') >= 1;
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

    private function makeSnapshotCacheKey(array $program_account_ids): string
    {
        $program_account_ids = $this->normalizeProgramAccountIds($program_account_ids);

        return self::CACHE_KEY_PREFIX . ':' . sha1(implode(',', $program_account_ids));
    }

    private function hasMatchingProgramSet(array $snapshot, array $program_account_ids): bool
    {
        $snapshot_program_account_ids = $snapshot['program_account_ids'] ?? null;
        if (!is_array($snapshot_program_account_ids)) {
            return false;
        }

        return $this->normalizeProgramAccountIds($snapshot_program_account_ids) === $program_account_ids;
    }

    private function normalizeProgramAccountIds(array $program_account_ids): array
    {
        $program_account_ids = array_values(array_unique($program_account_ids));
        sort($program_account_ids);

        return $program_account_ids;
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
        $entry = $this->CacheManager->fetch($key);
        return $entry['data'] ?? null;
    }

    private function cacheStore(string $key, mixed $value, int $ttl): void
    {
        $this->CacheManager->store($key, $value, $ttl, [
            'scope' => 'mtla_rp_report_snapshot',
        ]);
    }
}
