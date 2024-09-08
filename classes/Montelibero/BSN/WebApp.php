<?php

namespace Montelibero\BSN;

use Montelibero\BSN\Relations\Member;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Twig\Environment;

class WebApp
{
    public const MTLA_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;

    public array $sort_tags_example = [
        'Friend', 'Like', 'Dislike',
        'A', 'B', 'C', 'D',
        'Spouse', 'Love', 'OneFamily', 'Guardian', 'Ward', 'Sympathy', 'Divorce',
        'Employer', 'Employee', 'Contractor', 'Client', 'Partnership', 'Collaboration',
        'Owner', 'OwnershipFull', 'OwnerMajority', 'OwnershipMajority', 'OwnerMinority',
        'MyJudge',
        'Signer',
        'FactionMember', 'WelcomeGuest',
    ];

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
    }

    public function Index(): ?string
    {
        $Template = $this->Twig->load('index.twig');
        return $Template->render([
            'accounts_count' => $this->BSN->getAccountsCount(),
        ]);
    }

    public function Accounts(): ?string
    {
        $Template = $this->Twig->load('accounts_list.twig');
        $accounts = [];
        foreach ($this->BSN->getAccounts() as $Account) {
            $accounts[] = [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
                'display_name' => $Account->getDisplayName(),
            ];
        }
        return $Template->render([
            'accounts' => $accounts,
        ]);
    }

    public function Account(string $id): ?string
    {
        $Account = null;

        if ($this->BSN::validateStellarAccountIdFormat($id)) {
            $Account = $this->BSN->makeAccountById($id);
        }

        if (!$Account) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $income_tags = [];
        foreach ($Account->getIncomeTags() as $Tag) {
            $tag_data = [
                'name' => $Tag->getName(),
                'accounts' => [],
            ];
            foreach ($Account->getIncomeLinks($Tag) as $LinkAccount) {
                $tag_data['accounts'][] = $LinkAccount->jsonSerialize();
            }
            $income_tags[$Tag->getName()] = $tag_data;
        }
        $this->semantic_sort_keys($income_tags, $this->sort_tags_example);

        $outcome_tags = [];
        foreach ($Account->getOutcomeTags() as $Tag) {
            $tag_data = [
                'name' => $Tag->getName(),
                'accounts' => [],
            ];
            foreach ($Account->getOutcomeLinks($Tag) as $LinkAccount) {
                $tag_data['accounts'][] = $LinkAccount->jsonSerialize();
            }
            $outcome_tags[$Tag->getName()] = $tag_data;
        }
        $this->semantic_sort_keys($outcome_tags, $this->sort_tags_example);

        $Template = $this->Twig->load('accounts_item.twig');
        return $Template->render([
            'account_id' => $Account->getId(),
            'account_short_id' => $Account->getShortId(),
            'display_name' => $Account->getDisplayName(),
            'name' => $Account->getName(),
            'about' => $Account->getAbout(),
            'website' => $Account->getWebsite(),
            'income_tags' => $income_tags,
            'outcome_tags' => $outcome_tags,
        ]);
    }

    public function checkTelegramAuthorization($auth_data): bool
    {
        unset($auth_data['return_to']);
        $check_hash = $auth_data['hash'];
        unset($auth_data['hash']);
        $data_check_arr = [];
        foreach ($auth_data as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        sort($data_check_arr);
        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = hash('sha256', $_ENV['TELEGRAM_BOT_API_KEY'], true);
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);
        if (strcmp($hash, $check_hash) !== 0) {
            return false;
        }
        if ((time() - $auth_data['auth_date']) > 86400 * 30) {
            return false;
        }

        return true;
    }

    public function TgLogin()
    {
        if ($_GET && $_GET['hash'] && $this->checkTelegramAuthorization($_GET)) {
            if (!$_GET['username']) {
                die('В вашем телеграме не установлен username, а мы так не умеем :)');
            }

            $_SESSION['telegram'] = $_GET;

            if ($Account = $this->BSN->getAccountByTelegramId((string) $_GET['id'])) {
                $_SESSION['stellar_id'] = $Account->getId();
                $Relation = $Account->getRelation();
                if (($Relation instanceof Member) && $Relation->getLevel() >= 2) {
                    $_SESSION['show_telegram_usernames'] = true;
                }
            }
        }

        SimpleRouter::response()->redirect($_GET['return_to'] ?? '/', 301);
    }

    public function TgLogout()
    {
        unset($_SESSION['telegram']);
        unset($_SESSION['stellar_id']);
        unset($_SESSION['show_telegram_usernames']);
        SimpleRouter::response()->redirect($_GET['return_to'] ?? '/', 301);
    }

    public function semantic_sort_keys(array & $data, array $sort_example): void
    {
        uksort($data, function($a, $b) use ($sort_example) {
            $indexA = array_search($a, $sort_example);
            $indexB = array_search($b, $sort_example);

            // Если оба ключа есть в массиве сортировки
            if ($indexA !== false && $indexB !== false) {
                return $indexA - $indexB;
            }
            // Если ключ A в массиве сортировки, а B нет
            elseif ($indexA !== false) {
                return -1;
            }
            // Если ключ B в массиве сортировки, а A нет
            elseif ($indexB !== false) {
                return 1;
            }
            // Если ни один из ключей не в массиве сортировки, сортируем их по алфавиту
            else {
                return $a <=> $b;
            }
        });
    }

    public function Tags(): ?string
    {
        $tags = [];
        foreach ($this->BSN->getLinks() as $Link) {
            $Tag = $Link->getTag();
            if (!array_key_exists($Tag->getName(), $tags)) {
                $tags[$Tag->getName()] = [
                    'name' => $Tag->getName(),
                    'is_single' => $Tag->isSingle(),
                    'out' => [],
                    'in' => [],
                ];
            }
            $tags[$Tag->getName()]['out'][] = $Link->getSourceAccount()->getId();
            $tags[$Tag->getName()]['in'][] = $Link->getTargetAccount()->getId();
        }

        foreach ($tags as $tag_name => $tagData) {
            $tags[$tag_name]['out'] = count(array_unique($tagData['out']));
            $tags[$tag_name]['in'] = count(array_unique($tagData['in']));
        }

        $Template = $this->Twig->load('tags.twig');
        return $Template->render([
            'tags' => $tags,
        ]);
    }

    public function Tag($name)
    {
        $Tag = $this->BSN->getTag($name);

        if (!$Tag) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $links = [];

        foreach ($this->BSN->getLinks() as $Link) {
            if ($Link->getTag()->getName() !== $name) {
                continue;
            }
            $links[] = [
                'source_account' => $Link->getSourceAccount()->jsonSerialize(),
                'target_account' => $Link->getTargetAccount()->jsonSerialize(),
            ];
        }

        $Template = $this->Twig->load('tags_item.twig');
        return $Template->render([
            'tag_name' => $Tag->getName(),
            'links' => $links,
        ]);
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
            $current_signers[] = [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
                'display_name' => $Account->getDisplayName(),
                'sign_weight' => $weight,
            ];
        }

        $accounts_to_delegate = $this->fetchMtlaCouncilDelegations();

        $delegations = [];
        foreach ($accounts_to_delegate as $account_id => $delegate) {
            $Account = $this->BSN->makeAccountById($account_id);
            $member_level = 0;
            if (($Relation = $Account->getRelation()) && $Relation instanceof Member) {
                $member_level = $Relation->getLevel();
            }
            $record = [
                'account' => [
                    'id' => $Account->getId(),
                    'short_id' => $Account->getShortId(),
                    'display_name' => $Account->getDisplayName(),
                ],
                'member_level' => $member_level,
                'ready_to_council' => $delegate === 'ready',
            ];
            if ($delegate && $delegate !== 'ready') {
                $DelegateAccount = $this->BSN->makeAccountById($delegate);
                $record['delegate'] = [
                    'id' => $DelegateAccount->getId(),
                    'short_id' => $DelegateAccount->getShortId(),
                    'display_name' => $DelegateAccount->getDisplayName(),
                ];
            }
            $delegations[] = $record;
        }

        return $Template->render([
            'current_signers' => $current_signers,
            'delegations' => $delegations,
        ]);
    }

    public function EditorForm(): string
    {
        if (($id = $_GET['id'] ?? null) && $this->BSN::validateStellarAccountIdFormat($id)) {
            SimpleRouter::response()->redirect('/editor/' . $id, 301);
        }
        $Template = $this->Twig->load('editor_form.twig');
        return $Template->render([
            'default_id' => $_GET['id'] ?? $_SESSION['stellar_id'] ?? '',
        ]);
    }

    private function decideEditableTags(Account $Account) :array
    {
        $tags = [];
        foreach ($this->BSN->getTags() as $Tag) {
            if ($Tag->isStandard() || $Tag->isPromote()) {
                $tags[] = $Tag;
            }
        }

        $tags = array_merge($tags, $Account->getOutcomeTags(), $Account->getIncomeTags());

        $tags = array_filter($tags, fn(Tag $Tag) => $Tag->isEditable());

        $tags = array_combine(
            array_map(fn($Tag) => $Tag->getName(), $tags),
            $tags
        );

        return $tags;
    }

    public function Editor($id): string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($id)) {
            SimpleRouter::response()->redirect('/editor/', 301);
        }

        $Account = $this->BSN->makeAccountById($id);

        $tags = $this->decideEditableTags($Account);

        /** @var Account[] $contacts */
        $contacts = [];
        foreach ($tags as $Tag) {
            foreach (array_merge($Account->getOutcomeLinks($Tag), $Account->getIncomeLinks($Tag)) as $Contact) {
                $contacts[$Contact->getId()] = $Contact;
            }
        }

        $values = [];
        $single_tag_has_value = [];
        foreach ($Account->getOutcomeTags() as $Tag) {
            if (!$Tag->isEditable()) {
                continue;
            }
            foreach ($Account->getOutcomeLinks($Tag) as $Contact) {
                $values[$Contact->getId()] = $values[$Contact->getId()] ?? [];
                $values[$Contact->getId()][$Tag->getName()] = true;

                if ($Tag->isSingle()) {
                    $single_tag_has_value[$Tag->getName()] = true;
                }
            }
        }

        $Template = $this->Twig->load('editor.twig');
        return $Template->render([
            'account' => [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
                'display_name' => $Account->getDisplayName(),
            ],
            'tags' => array_map(fn(Tag $Tag) => [
                'name' => $Tag->getName(),
                'is_single' => $Tag->isSingle(),
            ], $tags),
            'contacts' => array_map(fn($Contact) => $Contact->jsonSerialize(), $contacts),
            'values' => $values,
            'single_tag_has_value' => $single_tag_has_value,
        ]);
    }

    public function EditorSave($id): string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($id)) {
            SimpleRouter::response()->redirect('/editor/', 301);
        }

        $Account = $this->BSN->makeAccountById($id);

        $current_values = [];
        foreach ($Account->getOutcomeTags() as $Tag) {
            if (!$Tag->isEditable()) {
                continue;
            }
            if ($Tag->isSingle()) {
                $current_values[$Tag->getName()] = null;
            }
            foreach ($Account->getOutcomeLinks($Tag) as $Contact) {
                if ($Tag->isSingle()) {
                    $current_values[$Tag->getName()] = $Contact->getId();
                } else {
                    $current_values[$Tag->getName()] = $current_values[$Tag->getName()] ?? [];
                    $current_values[$Tag->getName()][$Contact->getId()] = true;
                }
            }
        }

//        print '<pre>';

        // Removed
        $to_remove = [];
        foreach ($current_values as $tag_name => $data) {
            $Tag = $this->BSN->getTag($tag_name);
            if ($Tag->isSingle()) {
                if (!$_POST['tag'][$tag_name]) {
                    $to_remove[$tag_name] = true;
                }
            } else {
                foreach ($data as $account_id => $on) {
                    if (!isset($_POST['tag'][$tag_name][$account_id])) {
                        $to_remove[$tag_name] = $to_remove[$tag_name] ?? [];
                        $to_remove[$tag_name][] = $account_id;
                    }
                }
            }
        }
//        print "Remove:\n";
//        print_r($to_remove);

        // Added
        $to_add = [];
        foreach ($_POST['tag'] as $tag_name => $data) {
            $Tag = $this->BSN->getTag($tag_name);
            if ($Tag->isSingle()) {
                if ($_POST['tag'][$tag_name] && $_POST['tag'][$tag_name] !== $current_values[$tag_name]) {
                    $to_add[$tag_name] = $data;
                }
            } else {
                foreach ($data as $account_id => $on) {
                    if (!isset($current_values[$tag_name][$account_id])) {
                        $to_add[$tag_name] = $to_add[$tag_name] ?? [];
                        $to_add[$tag_name][] = $account_id;
                    }
                }
            }
        }
//        print "Add:\n";
//        print_r($to_add);

        $last_tag_sufix = [];
        $to_remove_data_keys = [];
        foreach ($this->fetchAccountData($id) as $key_name => $value) {
            $value = base64_decode($value);
            if (!$this->BSN::validateStellarAccountIdFormat($value)) {
                continue;
            }
            if (!preg_match('/^\s*(?<tag>[a-z0-9_]+?)\s*(:\s*(?<extra>[a-z0-9_]+?))?\s*(?<sufix>\d*)\s*$/i', $key_name, $m)) {
                continue;
            }
            $tag = $m['tag'] . ($m['extra'] ? ':' . $m['extra'] : '');
//            var_dump($tag);
            $last_tag_sufix[$tag] = max($last_tag_sufix[$tag] ?? 0, $m['sufix'] ?: 0);
            if (array_key_exists($tag, $to_remove)) {
                if (
                    (is_array($to_remove[$tag]) && in_array($value, $to_remove[$tag]))
                    || !is_array($to_remove[$tag])
                ) {
//                  print_r($key_name);
                    $to_remove_data_keys[] = $key_name;
                }
            }
        }
//        print "Sufixes:\n";
//        print_r($last_tag_sufix);
//        print "To remove keys:\n";
//        print_r($to_remove_data_keys);

        $Stellar = $this->Stellar;

        $StellarAccount = $Stellar->requestAccount($id);
        $Transaction = new TransactionBuilder($StellarAccount);
        $Transaction->addMemo(Memo::text('BSN update'));
        $Transaction->setMaxOperationFee(10000);
        $operations = [];

        foreach ($to_remove_data_keys as $key) {
            $Operation = new ManageDataOperationBuilder($key, null);
            $operations[] = $Operation->build();
        }

        foreach ($to_add as $key => $data) {
            if (is_array($data)) {
                foreach ($data as $account_id) {
                    $last_tag_sufix[$key] = $last_tag_sufix[$key] ?? 0;
                    $last_tag_sufix[$key]++;
                    $key_name = $key . $last_tag_sufix[$key];
                    $Operation = new ManageDataOperationBuilder($key_name, $account_id);
                    $operations[] = $Operation->build();
                }
            } else {
                $Operation = new ManageDataOperationBuilder($key, $data);
                $operations[] = $Operation->build();
            }
        }

        $xdr = null;
        if ($operations) {
            $Transaction->addOperations($operations);
            $xdr = $Transaction->build()->toEnvelopeXdrBase64();
        } else {
            SimpleRouter::response()->redirect(
                SimpleRouter::getUrl('editor', ['id' => $Account->getId()]),
                301
            );
        }

        $Template = $this->Twig->load('editor_result.twig');
        return $Template->render([
            'account' => [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
                'display_name' => $Account->getDisplayName(),
            ],
            'xdr' => $xdr,
        ]);
    }

    private function fetchAccountData(string $id): array
    {
        return $this->Stellar->requestAccount($id)->getData()->getData();
    }
}
