<?php

namespace Montelibero\BSN;

use League\Uri\Exceptions\SyntaxError;
use League\Uri\Http;
use Montelibero\BSN\Relations\Member;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use splitbrain\phpQRCode\QRCode;
use Twig\Environment;

class WebApp
{
    public const MTLA_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';

    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;

    public static array $sort_tags_example = [
        'Friend', 'Like', 'Dislike',
        'A', 'B', 'C', 'D',
        'Spouse', 'Love', 'OneFamily', 'Guardian', 'Ward', 'Sympathy', 'Divorce',
        'Employer', 'Employee', 'Contractor', 'Client', 'Partnership', 'Collaboration',
        'OwnershipFull', 'OwnershipMajority', 'OwnerMajority', 'OwnerMinority', 'Owner',
        'MyJudge',
        'Signer',
        'FactionMember', 'WelcomeGuest',
    ];

    private ?string $default_viewer = null;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;

        if ($_COOKIE['default_viewer']) {
            $this->default_viewer = $_COOKIE['default_viewer'];
        }
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
                'bsn_score' => $Account->calcBsnScore(),
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

        if ($this->default_viewer === 'brainbox'
            && (
                !isset($_SERVER['HTTP_REFERER'])
                || !str_contains($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST'])
            )
        ) {
            SimpleRouter::response()->redirect('https://bsn.brainbox.no/accounts/' . $Account->getId(), 302);
        }

        $income_tags = [];
        foreach ($Account->getIncomeTags() as $Tag) {
            $Pair = $Tag->getPair();
            $tag_data = [
                'name' => $Tag->getName(),
                'pair' => $Pair?->getName(),
                'pair_strong' => $Pair && $Tag->isPairStrong(),
                'links' => [],
            ];
            foreach ($Account->getIncomeLinks($Tag) as $LinkAccount) {
                $tag_data['links'][] = [
                    'account' => $LinkAccount->jsonSerialize(),
                    'has_pair' => $Pair && in_array($LinkAccount, $Account->getOutcomeLinks($Pair)),
                ];
            }
            $income_tags[$Tag->getName()] = $tag_data;
        }
        $this::semantic_sort_keys($income_tags, $this::$sort_tags_example);

        $outcome_tags = [];
        foreach ($Account->getOutcomeTags() as $Tag) {
            $Pair = $Tag->getPair();
            $tag_data = [
                'name' => $Tag->getName(),
                'pair' => $Pair?->getName(),
                'pair_strong' => $Pair && $Tag->isPairStrong(),
                'links' => [],
            ];
            foreach ($Account->getOutcomeLinks($Tag) as $LinkAccount) {
                $tag_data['links'][] = [
                    'account' => $LinkAccount->jsonSerialize(),
                    'has_pair' => $Pair && in_array($LinkAccount, $Account->getIncomeLinks($Pair)),
                ];
            }
            $outcome_tags[$Tag->getName()] = $tag_data;
        }
        $this::semantic_sort_keys($outcome_tags, $this::$sort_tags_example);

        $Template = $this->Twig->load('accounts_item.twig');
        return $Template->render([
            'account_id' => $Account->getId(),
            'account_short_id' => $Account->getShortId(),
            'display_name' => $Account->getDisplayName(),
            'telegram_username' => $Account->getTelegramUsername(),
            'name' => $Account->getName(),
            'about' => $Account->getAbout(),
            'website' => array_values(array_filter(array_map(self::normalizeURL(...), $Account->getWebsite()))),
            'bsn_score' => $Account->calcBsnScore(),
            'income_tags' => $income_tags,
            'outcome_tags' => $outcome_tags,
        ]);
    }

    public function AccountAndList(string $id): ?string
    {
        $Account = null;

        if ($this->BSN::validateStellarAccountIdFormat($id)) {
            $Account = $this->BSN->makeAccountById($id);
        }

        if (!$Account) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $connections = [];
        /** @var Account[] $contacts */
        $tags = array_merge($Account->getOutcomeTags(), $Account->getIncomeTags());
        foreach ($tags as $Tag) {
            /** @var Account $Contact */
            foreach (array_merge($Account->getOutcomeLinks($Tag), $Account->getIncomeLinks($Tag)) as $Contact) {
                $connection = $Contact->jsonSerialize();
                $connection['bsn_score'] = $Contact->calcBsnScore();
                $connections[$Contact->getId()] = $connection;

            }
        }

        $Template = $this->Twig->load('account_and_list.twig');
        return $Template->render([
            'account_id' => $Account->getId(),
            'account_short_id' => $Account->getShortId(),
            'display_name' => $Account->getDisplayName(),
            'connections' => $connections,
        ]);
    }

    public function AccountAnd(string $id1, string $id2): ?string
    {
        $Account1 = null;

        if ($this->BSN::validateStellarAccountIdFormat($id1)) {
            $Account1 = $this->BSN->makeAccountById($id1);
        }

        if (!$Account1) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $Account2 = null;

        if ($this->BSN::validateStellarAccountIdFormat($id2)) {
            $Account2 = $this->BSN->makeAccountById($id2);
        }

        if (!$Account2) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        /** @var Tag[] $acc1_tags */
        $acc1_tags = [];
        foreach ($Account1->getOutcomeTags() as $OutcomeTag) {
            foreach ($Account1->getOutcomeLinks($OutcomeTag) as $Account) {
                if ($Account->getId() !== $Account2->getId()) {
                    continue;
                }
                $acc1_tags[$OutcomeTag->getName()] = $OutcomeTag;
            }
        }
        /** @var Tag[] $acc2_tags */
        $acc2_tags = [];
        foreach ($Account2->getOutcomeTags() as $OutcomeTag) {
            foreach ($Account2->getOutcomeLinks($OutcomeTag) as $Account) {
                if ($Account->getId() !== $Account1->getId()) {
                    continue;
                }
                $acc2_tags[$OutcomeTag->getName()] = $OutcomeTag;
            }
        }
        $common_tags = array_merge($acc1_tags, $acc2_tags);
        $this::semantic_sort_keys($common_tags, $this::$sort_tags_example);

        $links = [];
        /*
         * Теги могут быть: тупо одинаковые, MyJudge
         * Могут быть не одинаковые, но и не парные
         * Могут быть парными, не не строго
         * Могут быть парными строго и
         */
        foreach ($common_tags as $tag_name => $Tag) {
            $link_data = [];
            if (isset($acc1_tags[$tag_name])) {
                $link_data['acc1'] = [
                    'tag_name' => $Tag->getName(),
                ];
                $Pair = $Tag->getPair() ?? $Tag;
                if (isset($acc2_tags[$Pair->getName()])) {
                    $link_data['acc2'] = [
                        'tag_name' => $Pair->getName(),
                    ];
                }
            }
            if (isset($link_data['acc1']) && isset($acc2_tags[$tag_name])) {
                $link_data['acc2'] = [
                    'tag_name' => $Tag->getName(),
                ];
                $Pair = $Tag->getPair() ?? $Tag;
                if (isset($acc1_tags[$Pair->getName()])) {
                    $link_data['acc1'] = [
                        'tag_name' => $Pair->getName(),
                    ];
                }
            }
            if ($link_data) {
                $links[] = $link_data;
            }
        }

        $Template = $this->Twig->load('account_and.twig');
        return $Template->render([
            'account1_id' => $Account1->getId(),
            'account1_short_id' => $Account1->getShortId(),
            'account1_display_name' => $Account1->getDisplayName(),
            'account2_id' => $Account2->getId(),
            'account2_short_id' => $Account2->getShortId(),
            'account2_display_name' => $Account2->getDisplayName(),
            'links' => $links,
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

        SimpleRouter::response()->redirect($_GET['return_to'] ?? '/', 302);
    }

    public function TgLogout()
    {
        unset($_SESSION['telegram']);
        unset($_SESSION['stellar_id']);
        unset($_SESSION['show_telegram_usernames']);
        SimpleRouter::response()->redirect($_GET['return_to'] ?? '/', 302);
    }

    public static function semantic_sort_keys(array & $data, array $sort_example): void
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
        $Source = null;
        if (isset($_GET['source']) && BSN::validateStellarAccountIdFormat($_GET['source'])) {
            $Source = $this->BSN->makeAccountById($_GET['source']);
        }
        $Target = null;
        if (isset($_GET['target']) && BSN::validateStellarAccountIdFormat($_GET['target'])) {
            $Target = $this->BSN->makeAccountById($_GET['target']);
        }

        $tags = [];
        foreach ($this->BSN->getLinks() as $Link) {
            if ($Source && $Link->getSourceAccount() !== $Source) {
                continue;
            }
            if ($Target && $Link->getTargetAccount() !== $Target) {
                continue;
            }

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
        $filter_query = [];
        if ($Source) {
            $filter_query['source'] = $Source->getId();
        }
        if ($Target) {
            $filter_query['target'] = $Target->getId();
        }
        return $Template->render([
            'source' => $Source ? $Source->getId() : null,
            'target' => $Target ? $Target->getId() : null,
            'filter_query' => $filter_query ? http_build_query($filter_query) : '',
            'tags' => $tags,
        ]);
    }

    public function Tag($name): ?string
    {
        $Source = null;
        if (isset($_GET['source']) && BSN::validateStellarAccountIdFormat($_GET['source'])) {
            $Source = $this->BSN->makeAccountById($_GET['source']);
        }
        $Target = null;
        if (isset($_GET['target']) && BSN::validateStellarAccountIdFormat($_GET['target'])) {
            $Target = $this->BSN->makeAccountById($_GET['target']);
        }

        $Tag = $this->BSN->getTag($name);
        if (!$Tag && BSN::validateTagNameFormat($name)) {
            $Tag = $this->BSN->makeTagByName($name);
        }

        if (!$Tag) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $links = [];

        foreach ($this->BSN->getLinks() as $Link) {
            if ($Link->getTag()->getName() !== $name) {
                continue;
            }
            if ($Source && $Link->getSourceAccount() !== $Source) {
                continue;
            }
            if ($Target && $Link->getTargetAccount() !== $Target) {
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
            SimpleRouter::response()->redirect('/editor/' . $id, 302);
        }
        $Template = $this->Twig->load('editor_form.twig');
        return $Template->render([
            'default_id' => $_GET['id'] ?? $_SESSION['stellar_id'] ?? '',
        ]);
    }

    /**
     * @param Account $Account
     * @return Tag[]
     */
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

        usort($tags, function($a, $b) {
            // Получаем названия тегов
            $nameA = $a->getName();
            $nameB = $b->getName();

            // Ищем позиции тегов в массиве $sort_example
            $indexA = array_search($nameA, WebApp::$sort_tags_example);
            $indexB = array_search($nameB, WebApp::$sort_tags_example);

            // Если оба тега есть в $sort_example, сортируем по их позициям
            if ($indexA !== false && $indexB !== false) {
                return $indexA - $indexB;
            }

            // Если один из тегов есть в $sort_example, а другой нет, придаём приоритет тому, что есть в $sort_example
            if ($indexA !== false) {
                return -1; // $a имеет приоритет, т.к. он есть в $sort_example
            }
            if ($indexB !== false) {
                return 1; // $b имеет приоритет, т.к. он есть в $sort_example
            }

            // Если ни один из тегов не найден в $sort_example, сортируем их по алфавиту
            return strcmp($nameA, $nameB);
        });

        return $tags;
    }

    /**
     * @param Tag[] $tags
     * @return array
     */
    private function decideTagGroups(array $tags): array
    {
        $groups = [
            'Social' => [
                'Friend', 'Like', 'Dislike',
            ],
            'Credit' => [
                'A', 'B', 'C', 'D',
            ],
            'Family' => [
                'Spouse', 'Love', 'OneFamily', 'Guardian', 'Ward',
            ],
            'Partnership' => [
                'Employer', 'Employee', 'Contractor', 'Client', 'Partnership', 'Collaboration',
            ],
            'Ownership' => [
                'Owner', 'OwnershipFull', 'OwnerMajority', 'OwnershipMajority', 'OwnerMinority',
            ],
            'MTLA' => [
                'FactionMember', 'RecommendToMTLA', 'RecommendForVerification',
                'LeaderForMTLA',
            ],
            'Delegation' => [
                'mtl_delegate',
                'tfm_delegate',
                'mtla_c_delegate', 'mtla_a_delegate',
            ],
            'Other' => [],
        ];

        $tag_groups = [];

        // Обход всех тегов
        foreach ($tags as $tag) {
            $tag_name = $tag->getName();
            $found = false;

            // Поиск, к какой группе принадлежит тег
            foreach ($groups as $group_name => $group_tags) {
                if (in_array($tag_name, $group_tags, true)) {
                    // Инициализация группы при первом добавлении
                    if (!isset($tag_groups[$group_name])) {
                        $tag_groups[$group_name] = [];
                    }
                    $tag_groups[$group_name][] = $tag_name;
                    $found = true;
                    break;
                }
            }

            // Если тег не нашелся в группах, добавляем его в Others
            if (!$found) {
                $tag_groups['Others'][] = $tag_name;
            }
        }

        WebApp::semantic_sort_keys($tag_groups, array_keys($groups));

        return $tag_groups;
    }

    public function Editor($id): string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($id)) {
            SimpleRouter::response()->redirect('/editor/', 302);
        }

        $Account = $this->BSN->makeAccountById($id);

        $tags = $this->decideEditableTags($Account);
        $group_tags = $this->decideTagGroups($tags);

        /** @var Account[] $contacts */
        $contacts = [];
        foreach ($tags as $Tag) {
            foreach (array_merge($Account->getOutcomeLinks($Tag), $Account->getIncomeLinks($Tag)) as $Contact) {
                $contacts[$Contact->getId()] = $Contact;
            }
        }
        // Add from contact book
        if ($_SESSION['telegram']) {
            $ContactsManager = new ContactsManager($_SESSION['telegram']['id']);
            foreach ($ContactsManager->getContacts() as $stellar_address => $item) {
                if (!array_key_exists($stellar_address, $contacts)) {
                    $contacts[$stellar_address] = $this->BSN->makeAccountById($stellar_address);
                }
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
            'group_tags' => $group_tags,
            'contacts' => array_map(fn($Contact) => $Contact->jsonSerialize(), $contacts),
            'values' => $values,
            'single_tag_has_value' => $single_tag_has_value,
        ]);
    }

    public function EditorSave($id): string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($id)) {
            SimpleRouter::response()->redirect('/editor/', 302);
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
                302
            );
        }

        $sep_07 = 'web+stellar:tx?xdr=' . urlencode($xdr);
        $qr_svg = QRCode::svg($sep_07);

        $Template = $this->Twig->load('editor_result.twig');
        return $Template->render([
            'account' => [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
                'display_name' => $Account->getDisplayName(),
            ],
            'operations_count' => count($operations),
            'xdr' => $xdr,
            'sep_07' => $sep_07,
            'qr_svg' => $qr_svg,
        ]);
    }

    private function fetchAccountData(string $id): array
    {
        return $this->Stellar->requestAccount($id)->getData()->getData();
    }

    public function Contacts()
    {
        if (!$_SESSION['telegram']) {
            SimpleRouter::response()->redirect('/tg/', 302);
        }

        $ContactsManager = new ContactsManager($_SESSION['telegram']['id']);

        $contacts = $ContactsManager->getContacts();

        foreach ($contacts as $stellar_account => &$contact) {
            $Account = $this->BSN->makeAccountById($stellar_account);
            $contact = $Account->jsonSerialize() + $contact;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach ($contacts as & $item) {
                if (!isset($_POST['update_' . $item['id']])) {
                    continue;
                }

                $given_name = trim($_POST['name_' . $item['id']] ?? '') ?: null;
                if ($item['name'] !== $given_name) {
                    $item['name'] = $given_name;
                    $ContactsManager->updateContact($item['id'], $given_name);
                }
            }

            if (
                ($_POST['new_stellar_account_1'] ?? null)
                && BSN::validateStellarAccountIdFormat($_POST['new_stellar_account_1'])
                && !array_key_exists($_POST['new_stellar_account_1'], $contacts)
            ) {
                $ContactsManager->addContact($_POST['new_stellar_account_1'], $_POST['new_name_1']);
            }

            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                $duplicates = $_POST['duplicates'] ?? 'ignore';
                $data = file_get_contents($_FILES['import_file']['tmp_name']);
                $data = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    $data = [];
                }
                foreach ($data as $address => $name) {
                    if (array_key_exists($address, $contacts)) {
                        if ($duplicates === 'update' && $name !== $contacts[$address]['name']) {
                            $ContactsManager->updateContact($address, $name);
                        }
                    } else {
                        $ContactsManager->addContact($address, $name ?: null);
                    }
                }
            }

            SimpleRouter::response()->redirect('/contacts', 302);
        }

        if (($_GET['export'] ?? null) === 'json') {
            header('Content-Disposition: attachment; filename="contacts.json"');
            header('Content-Type: application/json');

            $formatted_contacts = array_map(function ($contact) {
                return $contact['name'];
            }, $contacts);

            return json_encode($formatted_contacts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $Template = $this->Twig->load('contacts.twig');
            return $Template->render([
                'contacts' => $contacts,
            ]);
        }
    }

    /**
     * @param string $url
     * @return string|null
     */
    public static function normalizeURL(string $url): ?string
    {
        try {
            // Пробуем создать объект URL из строки
            $uri = Http::new($url);

            // Проверяем, содержит ли URL хотя бы хост
            if (!$uri->getHost()) {
                return null;
            }

            // Нормализуем URL (например, добавляем протокол, если отсутствует)
            if (!$uri->getScheme()) {
                $uri = $uri->withScheme('http');
            }

            return $uri->__toString();
        } catch (SyntaxError $e) {
            // Возвращаем null для некорректных ссылок
            return null;
        }
    }

    public function Defaults(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $variants = ['this', 'brainbox'];
            $viewer = in_array($_POST['viewer'], $variants) ? $_POST['viewer'] : 'this';
            $time = $viewer === 'this' ? time() - 86400 : time() + (6 * 30 * 24 * 60 * 60); // delete or 6 months
            setcookie(
                'default_viewer',
                $viewer,
                [
                    "expires" => $time,
                    "path" => "/",
                    "domain" => "",
                    "secure" => true,
                    "httponly" => true,
                    "samesite" => "Strict"
                ]
            );
            SimpleRouter::response()->redirect('/defaults', 302);
        }

        $Template = $this->Twig->load('defaults.twig');
        return $Template->render([
            'current_value' => $this->default_viewer,
        ]);
    }

    public function PercentPay(): ?string
    {
        if (empty($_SERVER['QUERY_STRING']) && !empty($_COOKIE['percent_pay'])) {
            $percent_pay = json_decode($_COOKIE['percent_pay'], true);
            if ($percent_pay) {
                SimpleRouter::response()->redirect('/tools/percent_pay?'.http_build_query($percent_pay), 302);
            }
        }

        $asset_issuer = $_GET['asset_issuer'] ?? null;
        if (!BSN::validateStellarAccountIdFormat($asset_issuer)) {
            $asset_issuer = null;
        }
        $asset_code = $_GET['asset_code'] ?? null;
        if (!preg_match('/[0-1a-zA-Z]{1,12}/', $asset_code)) {
            $asset_code = null;
        }
        $percent = $_GET['percent'] ?? null;
        $percent = str_replace(' ', '', $percent);
        $percent = str_replace(',', '.', $percent);
        if (!is_numeric($percent) || $percent == 0 || $percent == '' || $percent < 0) {
            $percent = null;
        }
        $payer_account = $_GET['payer_account'] ?? null;
        if (!BSN::validateStellarAccountIdFormat($payer_account)) {
            $payer_account = null;
        }
        $memo = $_GET['memo'] ?? null;

        $accounts = [];
        if ($asset_issuer && $asset_code && $percent) {
            $Accounts = $this->Stellar
                ->accounts()
                ->forAsset(
                    Asset::createNonNativeAsset(
                        $asset_code,
                        $asset_issuer
                    )
                )
                ->limit(200)
                ->execute();
            do {
                foreach ($Accounts->getAccounts() as $Account) {
                    $account = [
                        'id' => $Account->getAccountId(),
                    ];
                    /** @var AccountBalanceResponse $Balance */
                    foreach ($Account->getBalances() as $Balance) {
                        if (
                            $Balance->getAssetType() !== Asset::TYPE_NATIVE
                            && $Balance->getAssetIssuer() === $asset_issuer
                            && $Balance->getAssetCode() === $asset_code
                            && (float) $Balance->getBalance() > 0
                        ) {
                            $account['balance'] = $Balance->getBalance();
                            $accounts[] = $account;
                        }
                    }
                }
                $Accounts = $Accounts->getNextPage();
            } while ($Accounts->getAccounts()->count());
        }

        foreach ($accounts as & $account) {
            $Account = $this->BSN->makeAccountById($account['id']);
            $account = array_merge($account, $Account->jsonSerialize());

            $account['to_pay'] = bcmul($account['balance'], bcdiv($percent, "100", 7), 7);
            if ((float) $account['to_pay'] === 0.0) {
                $account['to_pay'] = null;
            }
        }
        unset($account);

        $transactions = [];
        if ($accounts && $payer_account) {
            $StellarAccount = $this->Stellar->requestAccount($payer_account);
            $Transaction = new TransactionBuilder($StellarAccount);
            if ($memo) {
                $Transaction->addMemo(Memo::text($memo));
            }
            $Transaction->setMaxOperationFee(10000);
            $Asset = Asset::createNonNativeAsset('EURMTL', 'GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V');
            $operations = [];
            $operations_limit = 50;
            foreach ($accounts as $account) {
                if (!$account['to_pay']) {
                    continue;
                }
                $Operation = new PaymentOperationBuilder($account['id'], $Asset, $account['to_pay']);
                $operations[] = $Operation->build();
                if (count($operations) > $operations_limit) {
                    $TransactionNext = clone $Transaction;
                    $TransactionNext->addOperations($operations);
                    $transactions[] = $TransactionNext->build()->toEnvelopeXdrBase64();
                    $operations = [];
                }
            }
            if ($operations) {
                $Transaction->addOperations($operations);
                $transactions[] = $Transaction->build()->toEnvelopeXdrBase64();
            }
        }

        $Template = $this->Twig->load('tools_percent_pay.twig');
        return $Template->render([
            'asset_issuer' => $asset_issuer,
            'asset_code' => $asset_code,
            'percent' => $percent,
            'payer_account' => $payer_account,
            'accounts' => $accounts,
            'transactions' => $transactions,
        ]);
    }

}
