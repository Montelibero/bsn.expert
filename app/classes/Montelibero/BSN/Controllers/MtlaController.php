<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\MongoCacheManager;
use Montelibero\BSN\MTLA\CalcDelegations\CalcVoices;
use Montelibero\BSN\MTLA\MtlaProgramReportService;
use Montelibero\BSN\Relations\Member;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Throwable;
use Twig\Environment;

class MtlaController implements RefreshDataCodeInterface
{
    use RefreshDataCodeTrait;

    public const MTLA_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';
    private const MTLA_COUNCIL_MEMBER_LIMIT = 20;
    private const MTLA_MULTISIG_ADDITIONAL_ACCOUNTS = [
        'GDUTNVJWCTJSPJEI3AWN7NRE535LAQDUEUEA37M22WGDYOLUGWKAMNFT',
        'GDRXBG5GVIUJWTAJDQE536JC5MDT5AH3MMCZIJCEGVAT2GEM2TMCROWD',
    ];
    private const MTLA_COUNCIL_DELEGATIONS_CACHE_KEY = 'mtla_council_delegations:v1';
    private const MTLA_COUNCIL_DELEGATIONS_CACHE_TTL = 259200;
    private const MTLA_COUNCIL_DELEGATIONS_FRESH_SECONDS = 60;
    private const MTLA_COUNCIL_DELEGATIONS_STALE_SECONDS = 86400;

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private MtlaProgramReportService $ReportService;
    private CurrentUser $CurrentUser;
    private SignController $SignController;
    private MongoCacheManager $CacheManager;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        StellarSDK $Stellar,
        MtlaProgramReportService $ReportService,
        CurrentUser $CurrentUser,
        SignController $SignController,
        MongoCacheManager $CacheManager
    ) {
        $this->BSN = $BSN;

        $this->Twig = $Twig;

        $this->Stellar = $Stellar;
        $this->ReportService = $ReportService;
        $this->CurrentUser = $CurrentUser;
        $this->SignController = $SignController;
        $this->CacheManager = $CacheManager;
    }

    public function Mtla(): ?string
    {
        $Template = $this->Twig->load('mtla.twig');
        $MtlaAccount = $this->BSN->makeAccountById(self::MTLA_ACCOUNT);
        return $Template->render([
            'mtla_account' => $MtlaAccount->jsonSerialize(),
        ]);
    }

    public function MtlaParticipants(): ?string
    {
        return $this->Twig->load('mtla_participants.twig')->render([
            'agreement_url' => $this->resolveAgreementUrl(),
            'personal_report' => $this->buildMembershipReport('MTLAP', 5),
            'corporate_report' => $this->buildMembershipReport('MTLAC', 4),
        ]);
    }

    private function fetchMtlaSigners(): array
    {
        if (apcu_exists('mtla_signers_list')) {
            return apcu_fetch('mtla_signers_list');
        }

        $current_signers = [];
        foreach ($this->Stellar->requestAccount(self::MTLA_ACCOUNT)->getSigners() as $Signer) {
            if ($Signer->getKey() === self::MTLA_ACCOUNT) {
                continue;
            }
            $current_signers[$Signer->getKey()] = $Signer->getWeight();
        }
        apcu_store('mtla_signers_list', $current_signers, 600);

        return $current_signers;
    }

    private function buildMembershipReport(string $asset_code, int $max_level): array
    {
        $levels = [];
        for ($level = 1; $level <= $max_level; $level++) {
            $levels[$level] = [];
        }

        foreach ($this->BSN->getAccounts() as $Account) {
            if ($Account->getId() === BSN::IGNORE_MEMBER_TOKENS) {
                continue;
            }

            $level = (int) floor($Account->getBalance($asset_code));
            if ($level < 1) {
                continue;
            }

            $level = min($level, $max_level);
            $levels[$level][] = $this->buildParticipantAccountData($Account);
        }

        $exact_counts = [];
        foreach ($levels as $level => & $accounts) {
            $this->sortParticipantAccounts($accounts);
            $exact_counts[$level] = count($accounts);
        }
        unset($accounts);

        $cumulative_counts = [];
        $higher_counts = [];
        $running_total = 0;
        for ($level = $max_level; $level >= 1; $level--) {
            $running_total += $exact_counts[$level];
            $cumulative_counts[$level] = $running_total;
            $higher_counts[$level] = $running_total - $exact_counts[$level];
        }

        ksort($cumulative_counts);
        ksort($higher_counts);

        return [
            'asset_code' => $asset_code,
            'total' => $running_total,
            'levels' => $levels,
            'exact_counts' => $exact_counts,
            'cumulative_counts' => $cumulative_counts,
            'higher_counts' => $higher_counts,
        ];
    }

    private function buildParticipantAccountData(\Montelibero\BSN\Account $Account): array
    {
        $sort_name = $Account->getName()[0]
            ?? $Account->getUsername()
            ?? $Account->getShortId();

        return $Account->jsonSerialize() + [
            'sort_name' => mb_strtolower($sort_name),
        ];
    }

    private function sortParticipantAccounts(array &$accounts): void
    {
        usort($accounts, static function (array $a, array $b): int {
            $sort_comparison = strcmp((string) ($a['sort_name'] ?? ''), (string) ($b['sort_name'] ?? ''));
            if ($sort_comparison !== 0) {
                return $sort_comparison;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
    }

    private function resolveAgreementUrl(): string
    {
        $locale = $_COOKIE['language'] ?? null;
        if (!$locale) {
            $locale = stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ru') !== false ? 'ru' : 'en';
        }

        return $locale === 'ru'
            ? 'https://docs.mtla.me/Agreement/Agreement.ru.html'
            : 'https://docs.mtla.me/Agreement/Agreement.en.html';
    }

    private function fetchMtlaCouncilDelegations(): array
    {
        $key = 'mtla_council_delegations';

        if (apcu_exists($key)) {
            return apcu_fetch($key);
        }

        $accounts_to_delegate = [];
        $Accounts = $this->Stellar
            ->accounts()
            ->forAsset(Asset::createNonNativeAsset('MTLAP', self::MTLA_ACCOUNT))
            ->execute();
        $accounts = [];
        do {
            foreach ($Accounts->getAccounts() as $Account) {
                $accounts[] = $Account;
            }
            $Accounts = $Accounts->getNextPage();
        } while ($Accounts->getAccounts()->count());

        foreach ($accounts as $AccountResponse) {
            if ($AccountResponse instanceof AccountResponse) {
                foreach ($AccountResponse->getBalances()->toArray() as $Asset) {
                    if (($Asset instanceof AccountBalanceResponse)
                        && $Asset->getAssetCode() === 'MTLAP'
                        && $Asset->getAssetIssuer() === self::MTLA_ACCOUNT
                    ) {
                        if (!(int) $Asset->getBalance()) {
                            continue 2;
                        } else {
                            break;
                        }
                    }
                }

                if (($delegate = $AccountResponse->getData()->get('mtla_c_delegate'))
                    && (
                        $this->BSN->validateStellarAccountIdFormat($delegate)
                        || $delegate === 'ready'
                    )
                ) {
                    $accounts_to_delegate[$AccountResponse->getAccountId()] = $delegate;
                } else {
                    $accounts_to_delegate[$AccountResponse->getAccountId()] = null;
                }
            }
        }

        apcu_store($key, $accounts_to_delegate, 600);

        return $accounts_to_delegate;
    }

    private function fetchMtlaTokenHolders(string $code, float $min_amount = 0): array
    {
        $holders = [];
        foreach ($this->BSN->getAccounts() as $Account) {
            $amount = $Account->getBalance($code);
            if ($amount < $min_amount) {
                continue;
            }

            $holders[] = [
                'id' => $Account->getId(),
                'amount' => $amount,
            ];
        }

        usort($holders, function (array $a, array $b): int {
            if ($a['amount'] === $b['amount']) {
                return strcmp($a['id'], $b['id']);
            }

            return $b['amount'] <=> $a['amount'];
        });

        return $holders;
    }

    public function MtlaCouncil(): ?string
    {
        $Template = $this->Twig->load('mtla_council.twig');

        $MtlaAccount = $this->BSN->makeAccountById(self::MTLA_ACCOUNT);
        $current_council_members = $this->buildCurrentCouncilMembersFromBsn();
        $current_council_threshold = (int) ($MtlaAccount->getMultisig()['thresholds'][1] ?? 0);
        $current_signers = [];
        foreach ($current_council_members as $member) {
            $current_signers[$member['id']] = $member;
        }

        $CalcVoices = new CalcVoices(
            $this->Stellar,
            self::MTLA_ACCOUNT,
            'MTLAP',
            self::MTLA_MULTISIG_ADDITIONAL_ACCOUNTS,
        );

        $can_refresh = $this->CurrentUser->isImpactActivist();
        $refresh_scope = self::MTLA_COUNCIL_DELEGATIONS_CACHE_KEY;
        $force_refresh = $can_refresh && $this->isRefreshDataRequested($refresh_scope);
        $council_snapshot = $this->fetchCouncilDelegationsSnapshot($CalcVoices, $force_refresh || isset($_GET['debug']));

        if ($force_refresh) {
            SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                'refresh_status' => ($council_snapshot['warning'] ?? null) !== null ? 'fallback' : null,
            ]), 302);
            return null;
        }

        $council_refresh = $this->buildRefreshDataContext($refresh_scope, $can_refresh);
        $council_refresh['status'] = (string) ($_GET['refresh_status'] ?? '');

        $data = $council_snapshot;
        $broken = $data['broken'];
        $this->fetchAccountData($broken, $current_signers, $data['council_candidates']);
        $delegation_tree = $data['roots'];
        $this->sortAccounts($delegation_tree);
        $this->fetchAccountData($delegation_tree, $current_signers, $data['council_candidates']);
        $show_all_delegations = ($_GET['show_all'] ?? '') === 'yes';
        $hidden_no_voice_accounts_count = 0;
        if (!$show_all_delegations) {
            $hidden_no_voice_accounts_count = $this->filterDelegationTreeNoVoiceLeaves($delegation_tree);
        }

        $council_update = null;
        $council_update_error = null;
        if ($this->CurrentUser->isAuthorized() && $this->CurrentUser->isImpactActivist()) {
            try {
                $council_update = $CalcVoices->buildCouncilUpdateTransaction(
                    $data['council_candidates'] ?? [],
                    self::MTLA_COUNCIL_MEMBER_LIMIT
                );
                if ($council_update) {
                    $this->fetchCouncilUpdateChangeAccountData($council_update['main_account_changes']);
                    $council_update['signing_form'] = $this->SignController->SignTransaction(
                        $council_update['xdr'],
                        null,
                        'Council Update'
                    );
                }
            } catch (Throwable $E) {
                $council_update_error = $E->getMessage();
            }
        }

        return $Template->render([
            'mtla_account' => $MtlaAccount->jsonSerialize(),
            'current_council_members' => $current_council_members,
            'current_council_threshold' => $current_council_threshold,
            'council_snapshot' => $council_snapshot,
            'council_refresh' => $council_refresh,
            'delegation_tree' => $delegation_tree ?? [],
            'show_all_delegations' => $show_all_delegations,
            'hidden_no_voice_accounts_count' => $hidden_no_voice_accounts_count,
            'show_all_delegations_url' => $this->getRefreshDataRedirectUri(['show_all' => 'yes']),
            'council_member_limit' => self::MTLA_COUNCIL_MEMBER_LIMIT,
            'council_update' => $council_update,
            'council_update_error' => $council_update_error,
        ]);
    }

    private function fetchCouncilDelegationsSnapshot(CalcVoices $CalcVoices, bool $force_refresh): array
    {
        $cached = $this->fetchCouncilDelegationsCache();
        if (!$force_refresh && is_array($cached) && $this->isValidCouncilDelegationsSnapshot($cached)) {
            $cached['from_cache'] = true;
            $cached['warning'] = null;
            return $this->finalizeCouncilDelegationsSnapshot($cached);
        }

        try {
            $CalcVoices->isDebugMode(isset($_GET['debug']));
            if (isset($_GET['debug'])) {
                print "<pre>";
            }
            $snapshot = $CalcVoices->run();
            if (isset($_GET['debug'])) {
                print "</pre>";
            }
            $snapshot['fetched_at'] = time();
            $this->storeCouncilDelegationsCache($snapshot);
            $snapshot['from_cache'] = false;
            $snapshot['warning'] = null;
            return $this->finalizeCouncilDelegationsSnapshot($snapshot);
        } catch (Throwable $Exception) {
            if (is_array($cached) && $this->isValidCouncilDelegationsSnapshot($cached)) {
                $cached['from_cache'] = true;
                $cached['warning'] = $Exception->getMessage();
                return $this->finalizeCouncilDelegationsSnapshot($cached);
            }

            return $this->finalizeCouncilDelegationsSnapshot([
                'fetched_at' => null,
                'broken' => [],
                'roots' => [],
                'council_candidates' => [],
                'from_cache' => false,
                'warning' => $Exception->getMessage(),
            ]);
        }
    }

    private function fetchCouncilDelegationsCache(): ?array
    {
        $entry = $this->CacheManager->fetch(self::MTLA_COUNCIL_DELEGATIONS_CACHE_KEY);
        $data = $entry['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    private function storeCouncilDelegationsCache(array $snapshot): void
    {
        $this->CacheManager->store(
            self::MTLA_COUNCIL_DELEGATIONS_CACHE_KEY,
            $snapshot,
            self::MTLA_COUNCIL_DELEGATIONS_CACHE_TTL,
            ['scope' => 'mtla_council_delegations']
        );
    }

    private function isValidCouncilDelegationsSnapshot(array $snapshot): bool
    {
        return isset($snapshot['broken'], $snapshot['roots'], $snapshot['council_candidates'])
            && is_array($snapshot['broken'])
            && is_array($snapshot['roots'])
            && is_array($snapshot['council_candidates']);
    }

    private function finalizeCouncilDelegationsSnapshot(array $snapshot): array
    {
        $fetched_at = isset($snapshot['fetched_at']) ? (int) $snapshot['fetched_at'] : 0;
        $age = $fetched_at > 0 ? max(0, time() - $fetched_at) : null;
        $snapshot['age_seconds'] = $age;
        $snapshot['is_fresh'] = $age !== null && $age < self::MTLA_COUNCIL_DELEGATIONS_FRESH_SECONDS;
        $snapshot['is_stale_cache'] = $age !== null && $age >= self::MTLA_COUNCIL_DELEGATIONS_STALE_SECONDS;

        return $snapshot;
    }

    private function buildCurrentCouncilMembersFromBsn(): array
    {
        $MtlaAccount = $this->BSN->makeAccountById(self::MTLA_ACCOUNT);
        $multisig = $MtlaAccount->getMultisig();
        if ($multisig === null) {
            return [];
        }

        $members = [];
        foreach ($multisig['signers'] ?? [] as $signer) {
            if (($signer['weight'] ?? 0) <= 0) {
                continue;
            }

            $Account = $signer['account'];
            if (!$Account instanceof \Montelibero\BSN\Account) {
                continue;
            }

            $members[] = $Account->jsonSerialize() + [
                'sign_weight' => (int) $signer['weight'],
            ];
        }

        usort($members, static function (array $a, array $b): int {
            $weight_comparison = ((int) ($b['sign_weight'] ?? 0)) <=> ((int) ($a['sign_weight'] ?? 0));
            if ($weight_comparison !== 0) {
                return $weight_comparison;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        return $members;
    }

    private function filterDelegationTreeNoVoiceLeaves(array &$accounts): int
    {
        $hidden_count = 0;
        $filtered = [];

        foreach ($accounts as $account) {
            if (!empty($account['delegated']) && is_array($account['delegated'])) {
                $hidden_count += $this->filterDelegationTreeNoVoiceLeaves($account['delegated']);
            }

            $has_own_voice = ((int) ($account['own_token_amount'] ?? 0)) > 0;
            $has_delegated_voice = ((int) ($account['delegated_token_amount'] ?? 0)) > 0;
            if (!$has_own_voice && !$has_delegated_voice) {
                $hidden_count++;
                continue;
            }

            $filtered[] = $account;
        }

        $accounts = $filtered;
        return $hidden_count;
    }

    private function fetchCouncilUpdateChangeAccountData(array &$changes): void
    {
        $emoji_by_type = [
            'added' => '🆕',
            'removed' => '🆓',
            'decreased' => '⬇️',
            'increased' => '⬆️',
            'changed' => '🔁',
        ];

        foreach ($changes as & $change) {
            $Account = $this->BSN->makeAccountById($change['id']);
            $change += $Account->jsonSerialize();
            $change['emoji'] = $emoji_by_type[$change['type']] ?? $emoji_by_type['changed'];
        }
    }

    private function sortAccounts(array &$accounts): void
    {
        foreach ($accounts as & $root) {
            if (!empty($root['delegated']) && is_array($root['delegated'])) {
                $this->sortAccounts($root['delegated']);
            }
        }

        usort($accounts, function ($a, $b) {
            $sumA = $a['own_token_amount'] + $a['delegated_token_amount'];
            $sumB = $b['own_token_amount'] + $b['delegated_token_amount'];

            if ($sumA === $sumB) {
                return strcmp($a['id'], $b['id']);
            }

            return $sumB <=> $sumA;
        });
    }

    private function fetchAccountData(array &$accounts, array $current_council, array $council_candidates): void
    {
        foreach ($accounts as & $root) {
            $Account = $this->BSN->makeAccountById($root['id']);
            $root += $Account->jsonSerialize();
            $root['is_council'] = array_key_exists($root['id'], $current_council);
            $root['has_no_voice'] = ((int) ($root['own_token_amount'] ?? 0)) <= 0;
            if (array_key_exists($root['id'], $council_candidates)) {
                $root['candidate_index'] = $council_candidates[$root['id']]['index'];
            }
            $root['show_ready_to_council'] = !empty($root['is_ready_to_council']) && !$root['is_council'];
            $root['show_council_outdated'] = $root['is_council']
                && (
                    empty($root['is_ready_to_council'])
                    || empty($root['candidate_index'])
                    || $root['candidate_index'] > self::MTLA_COUNCIL_MEMBER_LIMIT
                );

            if (!empty($root['delegated']) && is_array($root['delegated'])) {
                $this->fetchAccountData($root['delegated'], $current_council, $council_candidates);
            }
        }
    }

    public function MtlaReloadMembers(): ?string
    {
        self::reloadMembers();
        return "OK";
    }

    public static function reloadMembers(): void
    {
        $grist_response = \gristRequest(
            'https://montelibero.getgrist.com/api/docs/aYk6cpKAp9CDPJe51sP3AT/tables/Users/records',
            'GET'
        );
        $members = [];
        foreach ($grist_response['records'] as $item) {
            $fields = $item['fields'];
            if (
                empty($fields['TGID'])
                || empty($fields['Stellar'])
                || empty($fields['MTLAP'])
                || $fields['MTLAP'] == 0
            ) {
                continue;
            }
            $members[] = [
                'stellar' => $fields['Stellar'],
                'tg_id' => $fields['TGID'],
                'tg_username' => trim($fields['Telegram'], '@'),
            ];
        }
        apcu_store('mtla_members', $members, 3600);
    }

    public function MtlaPrograms(): ?string
    {
        $programs = $this->ReportService->collectPrograms();
        $current_account_id = $this->CurrentUser->getCurrentAccountId();
        $this->prioritizeProgramsByCoordinator($programs['items'], $current_account_id);
        $can_refresh = $this->ReportService->canRefreshSnapshot();
        $refresh_scope = 'mtla_programs_snapshot';
        $force_refresh = $can_refresh && $this->isRefreshDataRequested($refresh_scope);
        $snapshot = $this->ReportService->fetchMtlaSnapshot(
            array_map(
                static fn(array $item): string => $item['data']['id'],
                $programs['items']
            ),
            $force_refresh
        );

        if ($force_refresh) {
            SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                'refresh_status' => ($snapshot['warning'] ?? null) !== null ? 'fallback' : null,
            ]), 302);
            return null;
        }

        $refresh = $this->buildRefreshDataContext($refresh_scope, $can_refresh);
        $refresh['status'] = (string) ($_GET['refresh_status'] ?? '');

        return $this->Twig->load('mtla_programs.twig')->render([
            'programs' => $programs['items'],
            'snapshot' => $snapshot,
            'refresh' => $refresh,
        ]);
    }

    public function MtlaProgram(string $account_id): ?string
    {
        $programs = $this->ReportService->collectPrograms();
        $program = $this->ReportService->findProgramByAccountId($programs['items'], $account_id);
        $account = BSN::validateStellarAccountIdFormat($account_id)
            ? $this->BSN->makeAccountById($account_id)->jsonSerialize()
            : null;

        $snapshot = null;
        $refresh = null;
        $participants = [];
        $trustline_action = null;

        if ($program !== null) {
            $can_refresh = $this->ReportService->canRefreshSnapshot();
            $refresh_scope = 'mtla_program_snapshot:' . $account_id;
            $force_refresh = $can_refresh && $this->isRefreshDataRequested($refresh_scope);
            $snapshot = $this->ReportService->fetchMtlaSnapshot(
                array_map(
                    static fn(array $item): string => $item['data']['id'],
                    $programs['items']
                ),
                $force_refresh
            );
            $participants = $this->ReportService->buildProgramParticipantReport($program, $snapshot);
            $trustline_action = $this->buildProgramTrustlineAction($program, $participants);
            $refresh = $this->buildRefreshDataContext($refresh_scope, $can_refresh);
            $refresh['status'] = (string) ($_GET['refresh_status'] ?? '');

            if ($force_refresh) {
                SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                    'refresh_status' => ($snapshot['warning'] ?? null) !== null ? 'fallback' : null,
                ]), 302);
                return null;
            }
        }

        $Template = $this->Twig->load('mtla_program.twig');
        return $Template->render([
            'program' => $program,
            'program_account' => $account,
            'participants' => $participants,
            'snapshot' => $snapshot,
            'refresh' => $refresh,
            'mtla_account' => $program !== null ? $this->ReportService->getMtlaAccountData() : null,
            'min_required_tt' => $this->ReportService->getMinRequiredTt(),
            'trustline_action' => $trustline_action,
        ]);
    }

    private function prioritizeProgramsByCoordinator(array &$programs, ?string $current_account_id): void
    {
        if (!$current_account_id) {
            return;
        }

        $current = [];
        $others = [];

        foreach ($programs as $program) {
            if (($program['coordinator']['id'] ?? null) === $current_account_id) {
                $current[] = $program;
            } else {
                $others[] = $program;
            }
        }

        $programs = array_merge($current, $others);
    }

    private function buildProgramTrustlineAction(array $program, array $participants): ?array
    {
        if (!$this->canCurrentUserManageProgramTrustlines($program)) {
            return null;
        }

        $assets = [];
        foreach ($participants as $participant) {
            if (($participant['status']['code'] ?? null) !== 'missing_program_trustline') {
                continue;
            }

            $code = $participant['timetoken']['code'] ?? null;
            $issuer = $participant['timetoken']['issuer'] ?? null;
            if (!$code || !$issuer) {
                continue;
            }

            $asset_key = $code . '-' . $issuer;
            if (!isset($assets[$asset_key])) {
                $assets[$asset_key] = [
                    'code' => $code,
                    'issuer' => $issuer,
                    'url' => '/tokens/' . $asset_key,
                ];
            }
        }

        if (!$assets) {
            return null;
        }

        try {
            $StellarAccount = $this->Stellar->requestAccount($program['data']['id']);
            $Transaction = new TransactionBuilder($StellarAccount);

            foreach ($assets as $asset) {
                $Operation = new ChangeTrustOperationBuilder(Asset::createNonNativeAsset($asset['code'], $asset['issuer']));
                $Transaction->addOperation($Operation->build());
            }

            $xdr = $Transaction->build()->toEnvelopeXdrBase64();
        } catch (Throwable) {
            return null;
        }

        return [
            'assets' => array_values($assets),
            'signing_form' => $this->SignController->SignTransaction(
                $xdr,
                null,
                'Open missing trustlines for participant TT assets'
            ),
        ];
    }

    private function canCurrentUserManageProgramTrustlines(array $program): bool
    {
        if (!$this->CurrentUser->isAuthorized()) {
            return false;
        }

        $current_account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$current_account_id) {
            return false;
        }

        foreach ($program['participants'] as $participant) {
            if (($participant['id'] ?? null) === $current_account_id) {
                return true;
            }
        }

        return false;
    }
}
