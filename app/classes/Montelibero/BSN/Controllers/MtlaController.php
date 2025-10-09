<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\MTLA\CalcDelegations\CalcVoices;
use Montelibero\BSN\Relations\Member;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class MtlaController
{
    public const MTLA_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
    }

    public function Mtla(): ?string
    {
        $Template = $this->Twig->load('mtla.twig');
        return $Template->render();
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
                'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA',
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
        ]);
    }

    private function sortAccounts(array &$accounts): void
    {
        // Рекурсивная сортировка вложенных элементов
        foreach ($accounts as & $root) {
            if (!empty($root['delegated']) && is_array($root['delegated'])) {
                $this->sortAccounts($root['delegated']);
            }
        }

        // Сортировка текущего уровня по сумме `own_token_amount` + `delegated_token_amount`
        usort($accounts, function ($a, $b) {
            $sumA = $a['own_token_amount'] + $a['delegated_token_amount'];
            $sumB = $b['own_token_amount'] + $b['delegated_token_amount'];

            if ($sumA === $sumB) {
                return strcmp($a['id'], $b['id']);
            }

            return $sumB <=> $sumA; // По убыванию
        });
    }

    private function fetchAccountData(array &$accounts, array $current_council, array $council_candidates): void
    {
        // Рекурсивная обработка
        foreach ($accounts as & $root) {
            $Account = $this->BSN->makeAccountById($root['id']);
            $root += $Account->jsonSerialize();
            if (array_key_exists($root['id'], $current_council)) {
                $root['is_council'] = true;
            }
            if (array_key_exists($root['id'], $council_candidates)) {
                $root['candidate_index'] = $council_candidates[$root['id']]['index'];
            }

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
        /*
         * Получить список программ
         * Про каждую узнать координатора
         * Для каждой получить список участников
         */
        $MTLA = $this->BSN->getAccountById(self::MTLA_ACCOUNT);
        $TagProgram = $this->BSN->makeTagByName('Program');
        $TagProgramCoordinator = $this->BSN->makeTagByName('ProgramCoordinator');
        $TagMyPart = $this->BSN->makeTagByName('MyPart');
        $TagPartOf = $this->BSN->makeTagByName('PartOf');
        $programs = $MTLA->getOutcomeLinks($TagProgram);
        $programs_data = [];

        foreach ($programs as $Program) {
            $programs_data[$Program->getId()] = [
                'data' => $Program->jsonSerialize(),
                'coordinator' => null,
                'participants' => [],
            ];
            $coordinators = $Program->getOutcomeLinks($TagProgramCoordinator);
            if ($Coordinator = array_shift($coordinators)) {
                $programs_data[$Program->getId()]['coordinator'] = $Coordinator->jsonSerialize();
            }
            foreach ($Program->getOutcomeLinks($TagMyPart) as $Participant) {
                foreach ($Participant->getOutcomeLinks($TagPartOf) as $Part) {
                    if ($Part === $Program) {
                        $programs_data[$Program->getId()]['participants'][] = $Participant->jsonSerialize();
                        break;
                    }
                }
            }
        }

        // Сортировка: 1) с координатором первыми; 2) по кол-ву участников по убыванию
        usort($programs_data, function (array $a, array $b): int {
            $has_coordinator_a = !empty($a['coordinator']);
            $has_coordinator_b = !empty($b['coordinator']);
            if ($has_coordinator_a !== $has_coordinator_b) {
                return $has_coordinator_b <=> $has_coordinator_a; // true раньше false
            }

            $count_a = is_countable($a['participants']) ? count($a['participants']) : 0;
            $count_b = is_countable($b['participants']) ? count($b['participants']) : 0;
            if ($count_a !== $count_b) {
                return $count_b <=> $count_a; // по убыванию
            }

            // стабильный третий критерий: по имени аккаунта
            return strcmp($a['data']['display_name'], $b['data']['display_name']);
        });

        $Template = $this->Twig->load('mtla_programs.twig');
        return $Template->render([
            'programs' => $programs_data,
        ]);
    }
}
