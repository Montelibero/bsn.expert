<?php

namespace Montelibero\BSN\Controllers;

use League\Uri\Exceptions\SyntaxError;
use League\Uri\Http;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\Tag;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class AccountsController
{
    private BSN $BSN;
    private Environment $Twig;
    public static array $sort_tags_example = [
        'Friend',
        'Like',
        'Dislike',
        'A',
        'B',
        'C',
        'D',
        'Spouse',
        'Love',
        'OneFamily',
        'Guardian',
        'Ward',
        'Sympathy',
        'Divorce',
        'Employer',
        'Employee',
        'Contractor',
        'Client',
        'Partnership',
        'Collaboration',
        'OwnershipFull',
        'OwnerMajority',
        'Owner',
        'OwnerMinority',
        'MyJudge',
        'Signer',
        'FactionMember',
        'WelcomeGuest',
    ];

    private ?string $default_viewer = null;

    public function __construct(BSN $BSN, Environment $Twig)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        if (isset($_COOKIE['default_viewer']) && $_COOKIE['default_viewer']) {
            $this->default_viewer = $_COOKIE['default_viewer'];
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
//            var_dump($uri);

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

    public function getAdopters(): array
    {
        global $functional_tags;
        $free_tags = [];
        foreach ($this->BSN->getTags() as $Tag) {
            if (($Tag->isStandard() || $Tag->isPromote()) && !in_array($Tag->getName(), $functional_tags)) {
                $free_tags[] = $Tag->getName();
            }
        }
        /** @var Account[] $accounts */
        $accounts = [];
        foreach ($this->BSN->getAccounts() as $Account) {
            foreach ($Account->getOutcomeTags() as $Tag) {
                if (in_array($Tag->getName(), $free_tags)) {
                    $accounts[] = $Account;
                    continue 2;
                }
            }
        }

        return $accounts;
    }

    public function Accounts(): ?string
    {
        $Template = $this->Twig->load('accounts_list.twig');
        if (($_GET['adopters'] ?? null) == 'true') {
            $list_accounts = $this->getAdopters();
        } else {
            $list_accounts = $this->BSN->getAccounts();
        }
        $accounts = [];
        foreach ($list_accounts as $Account) {
            $accounts[] = $Account->jsonSerialize() + [
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

        $is_contact = false;
        $is_logged = false;
        if ($_SESSION['telegram'] ?? null) {
            $is_logged = true;
            $ContactsManager = new ContactsManager($_SESSION['telegram']['id']);
            $is_contact = (bool) $ContactsManager->getContact($Account->getId());
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
                $tag_data['links'][$LinkAccount->getId()] = [
                    'has_pair' => $Pair && in_array($LinkAccount, $Account->getOutcomeLinks($Pair)),
                ] + $LinkAccount->jsonSerialize();
            }
            $income_tags[$Tag->getName()] = $tag_data;
        }
        WebApp::semantic_sort_keys($income_tags, $this::$sort_tags_example);

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
                $tag_data['links'][$LinkAccount->getId()] = [
                    'has_pair' => $Pair && in_array($LinkAccount, $Account->getIncomeLinks($Pair)),
                ] + $LinkAccount->jsonSerialize();
            }
            $outcome_tags[$Tag->getName()] = $tag_data;
        }
        WebApp::semantic_sort_keys($outcome_tags, $this::$sort_tags_example);

        $signatures = array_map(function ($Signature) {
            return [
                'name' => $Signature->getName(),
            ];
        }, $Account->getSignatures());

        if ($_SERVER['HTTP_ACCEPT'] === 'application/json' || ($_GET['format'] ?? '') === 'json') {
            if (!empty($_GET['tag']) && BSN::validateTagNameFormat($_GET['tag'])) {
                $FilterTag = $this->BSN->makeTagByName($_GET['tag']);
                $FilterPairTag = $FilterTag->getPair();
                $filter_tags = function ($key) use ($FilterTag, $FilterPairTag) {
                    return $key === $FilterTag->getName() || ($FilterPairTag && $key === $FilterPairTag->getName());
                };
                $outcome_tags = array_filter($outcome_tags, $filter_tags, ARRAY_FILTER_USE_KEY);
                $income_tags = array_filter($income_tags, $filter_tags, ARRAY_FILTER_USE_KEY);
            }
            $result = [
                'account' => $Account->jsonSerialize(),
                'self_presentation' => [
                    'name' => $Account->getName(),
                    'about' => $Account->getAbout(),
                    'website' => $Account->getWebsite(),
                ],
                'links' => [
                    'outcome' => $outcome_tags,
                    'income' => $income_tags,
                ],
                'signatures' => $signatures,
            ];
            
            header('Content-type: application/json');
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $Template = $this->Twig->load('account_page.twig');
        return $Template->render([
            'canonical_url' => SimpleRouter::getUrl('account', ['id' => $Account->getId()]),
            'account_id' => $Account->getId(),
            'account_short_id' => $Account->getShortId(),
            'display_name' => $Account->getDisplayName(),
            'is_logged' => $is_logged,
            'is_contact' => $is_contact,
            'telegram_username' => $Account->getTelegramUsername(),
            'name' => $Account->getName(),
            'about' => $Account->getAbout(),
            'website' => array_values(array_filter(array_map(self::normalizeURL(...), $Account->getWebsite()))),
            'bsn_score' => $Account->calcBsnScore(),
            'income_tags' => $income_tags,
            'outcome_tags' => $outcome_tags,
            'signatures' => $signatures,
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

        $tags = [];
        $connections = [];
        /** @var Account[] $contacts */
        $all_tags = array_merge($Account->getOutcomeTags(), $Account->getIncomeTags());
        foreach ($all_tags as $Tag) {
            $tags[$Tag->getName()] = [
                'name' => $Tag->getName(),
            ];
            /** @var Account $Contact */
            foreach (array_merge($Account->getOutcomeLinks($Tag), $Account->getIncomeLinks($Tag)) as $Contact) {
                $connection = $Contact->jsonSerialize();
                $connection['bsn_score'] = $Contact->calcBsnScore();
                $connections[$Contact->getId()] = $connection;
            }
        }

        $Template = $this->Twig->load('account_and.twig');
        return $Template->render([
            'account_id' => $Account->getId(),
            'account_short_id' => $Account->getShortId(),
            'display_name' => $Account->getDisplayName(),
            'connections' => $connections,
            'tags' => $tags,
        ]);
    }

    public function AccountAnd(string $id1, string $id2): ?string
    {
        if ($this->BSN::validateStellarAccountIdFormat($id1)) {
            $Account = $this->BSN->makeAccountById($id1);
        } else {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        if ($this->BSN::validateStellarAccountIdFormat($id2)) {
            $Account2 = $this->BSN->makeAccountById($id2);
            return $this->AccountAndAccount($Account, $Account2);
        } else if ($this->BSN::validateTagNameFormat($id2)) {
            $Tag = $this->BSN->makeTagByName($id2);
            return $this->AccountAndTag($Account, $Tag);
        } else {
            SimpleRouter::response()->httpCode(404);
            return null;
        }
    }

    private function AccountAndAccount(Account $Account1, Account $Account2): ?string
    {
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
        WebApp::semantic_sort_keys($common_tags, $this::$sort_tags_example);

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
            // isset($link_data['acc1']) &&
            if (isset($acc2_tags[$tag_name])) {
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

        $Template = $this->Twig->load('account_and_account.twig');
        return $Template->render([
            'account1' => $Account1->jsonSerialize(),
            'account2' => $Account2->jsonSerialize(),
            'links' => $links,
        ]);
    }

    private function AccountAndTag(Account $Account, Tag $Tag): ?string
    {
        $Template = $this->Twig->load('account_and_tag.twig');

        $PairTag = $Tag->getPair() ?? $Tag;
        $is_pair = !!$Tag->getPair();
        $is_pair_strong = $Tag->isPairStrong();

        $links = [];
        foreach ($Account->getOutcomeLinks($Tag) as $Contact) {
            $links[$Contact->getId()] = [
                'contact' => $Contact->jsonSerialize(),
                'out' => true,
                'in' => false,
            ];
        }
        foreach ($Account->getIncomeLinks($PairTag) as $Contact) {
            if (array_key_exists($Contact->getId(), $links)) {
                $links[$Contact->getId()]['in'] = true;
            } else {
                $links[$Contact->getId()] = [
                    'contact' => $Contact->jsonSerialize(),
                    'out' => false,
                    'in' => true,
                ];
            }
        }

        $only_paired = ($_GET['only_paired'] ?? false) && $_GET['only_paired'] === 'true';

        if ($only_paired) {
            $links = array_filter($links, function ($link) {
                return $link['in'] && $link['out'];
            });
        }

        return $Template->render([
            'account_id' => $Account->getId(),
            'account_short_id' => $Account->getShortId(),
            'account_display_name' => $Account->getDisplayName(),
            'tag_name' => $Tag->getName(),
            'tag_pair_name' => $PairTag->getName(),
            'is_pair' => $is_pair,
            'is_pair_strong' => $is_pair_strong,
            'only_paired' => $only_paired,
            'links' => $links,
        ]);
    }

    public static function getAccountPagePath(Account $Account): string
    {
        if ($username = $Account->getUsername()) {
            return '/@' . $username;
        }

        return '/accounts/' . $Account->getId();
    }
}