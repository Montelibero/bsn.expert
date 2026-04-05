<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
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

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private MtlaProgramReportService $ReportService;
    private CurrentUser $CurrentUser;
    private SignController $SignController;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        StellarSDK $Stellar,
        MtlaProgramReportService $ReportService,
        CurrentUser $CurrentUser,
        SignController $SignController
    ) {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
        $this->ReportService = $ReportService;
        $this->CurrentUser = $CurrentUser;
        $this->SignController = $SignController;
    }

    public function Mtla(): ?string
    {
        $Template = $this->Twig->load('mtla.twig');
        $MtlaAccount = $this->BSN->makeAccountById(self::MTLA_ACCOUNT);
        return $Template->render([
            'mtla_account' => $MtlaAccount->jsonSerialize(),
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

        $current_signers = [];
        foreach ($this->fetchMtlaSigners() as $id => $weight) {
            $Account = $this->BSN->getAccountById($id);
            $current_signers[$id] = $Account->jsonSerialize() + [
                'sign_weight' => $weight,
            ];
        }

        $key = 'mtla_council_delegation_tree';

        if (!isset($_GET['debug']) && apcu_exists($key)) {
            $data = apcu_fetch($key);
        } else {
            $CalcVoices = new CalcVoices(
                $this->Stellar,
                self::MTLA_ACCOUNT,
                'MTLAP',
                ['GDUTNVJWCTJSPJEI3AWN7NRE535LAQDUEUEA37M22WGDYOLUGWKAMNFT'],
            );

            $CalcVoices->isDebugMode(isset($_GET['debug']));
            if (isset($_GET['debug'])) {
                print "<pre>";
            }
            $data = $CalcVoices->run();
            if (isset($_GET['debug'])) {
                print "</pre>";
            }
            apcu_store($key, $data, 60);
        }

        $broken = $data['broken'];
        $this->fetchAccountData($broken, $current_signers, $data['council_candidates']);
        $delegation_tree = $data['roots'];
        $this->sortAccounts($delegation_tree);
        $this->fetchAccountData($delegation_tree, $current_signers, $data['council_candidates']);

        return $Template->render([
            'delegation_tree' => $delegation_tree ?? [],
            'council_member_limit' => self::MTLA_COUNCIL_MEMBER_LIMIT,
        ]);
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
