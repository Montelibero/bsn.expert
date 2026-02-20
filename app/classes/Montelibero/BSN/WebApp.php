<?php
namespace Montelibero\BSN;

use DI\Container;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\CurrentUser;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class WebApp
{
    private BSN $BSN;
    private AccountsManager $AccountsManager;
    private Environment $Twig;
    private CurrentUser $CurrentUser;

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
    private Container $Container;

    public function __construct(
        BSN $BSN,
        AccountsManager $AccountsManager,
        Environment $Twig,
        Container $Container,
        CurrentUser $CurrentUser,
    ) {
        $this->BSN = $BSN;
        $this->AccountsManager = $AccountsManager;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);


        $this->Container = $Container;
        $this->CurrentUser = $CurrentUser;

        if (isset($_COOKIE['default_viewer']) && $_COOKIE['default_viewer']) {
            $this->default_viewer = $_COOKIE['default_viewer'];
        }
    }

    public function Search(): ?string
    {
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        if (BSN::validateStellarAccountIdFormat($q)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('account', ['id' => $q]));
            return null;
        }
        if ($account_id = $this->AccountsManager->fetchAccountIdByUsername($q)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('account', ['id' => $account_id]));
            return null;
        }

        if (BSN::validateTransactionHashFormat($q)) {
            $hash = strtolower($q);
            $known_document = $this->Container->get(DocumentsManager::class)->getDocument($hash) !== null;

            if ($known_document) {
                SimpleRouter::response()->redirect(SimpleRouter::getUrl('document_page', ['id' => $hash]));
                return null;
            }

            SimpleRouter::response()->redirect(SimpleRouter::getUrl('transaction_page', ['tx_hash' => $hash]));
            return null;
        }

        if (BSN::validateTokenNameFormat($q)) {
            $TokensController = $this->Container->get(TokensController::class);
            $known_tag = $TokensController->searchKnownTokenByCode($q);
            if ($known_tag) {
                SimpleRouter::response()->redirect(SimpleRouter::getUrl('token_page', ['code' => $known_tag['code']]));
                return null;
            }
        }

        if (BSN::validateTagNameFormat($q)) {
            $tag_name = $q;
            foreach ($this->BSN->getTags() as $Tag) {
                if (mb_strtolower($Tag->getName()) === $tag_name) {
                    $tag_name = $Tag->getName();
                    break;
                }
            }
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('tag', ['id' => $tag_name]));
            return null;
        }

        $Template = $this->Twig->load('search.twig');
        return $Template->render([
            'q' => $q,
        ]);
    }

    public static function semantic_sort_keys(array &$data, array $sort_example): void
    {
        // Создаём массив ключей, которые не присутствуют в sort_example, в их текущем порядке
        $original_keys = array_keys($data);
        $custom_keys_order = [];
        foreach ($original_keys as $key) {
            if (array_search($key, $sort_example, true) === false) {
                $custom_keys_order[] = $key;
            }
        }

        uksort($data, function ($a, $b) use ($sort_example, $custom_keys_order) {
            $indexA = array_search($a, $sort_example, true);
            $indexB = array_search($b, $sort_example, true);

            // Если оба ключа есть в sort_example
            if ($indexA !== false && $indexB !== false) {
                return $indexA - $indexB;
            }
            // Если ключ A есть в sort_example, а B нет
            if ($indexA !== false) {
                return -1;
            }
            // Если ключ B есть в sort_example, а A нет
            if ($indexB !== false) {
                return 1;
            }
            // Оба ключа не в sort_example — сортируем по порядку их появления в исходном массиве
            $origA = array_search($a, $custom_keys_order, true);
            $origB = array_search($b, $custom_keys_order, true);
            return $origA - $origB;
        });
    }

    public function Preferences(): ?string
    {
        $Translator = $this->Container->get(Translator::class);

        $current_account_error = null;
        $current_account_value = $this->CurrentUser->getCurrentAccountId();
        $current_account_input_value = $this->CurrentUser->isAuthorized() ? '' : $current_account_value;
        $current_language = $Translator->getLocale();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $time = time() + (6 * 30 * 24 * 60 * 60); // 6 months
            $variants = ['this', 'eurmtl', 'brainbox'];
            $viewer = in_array($_POST['viewer'], $variants) ? $_POST['viewer'] : '';
            $this->default_viewer = $viewer;
            setcookie(
                'default_viewer',
                $viewer,
                [
                    "expires" => $time * ($viewer ? 1 : -1),
                    "path" => "/",
                    "domain" => "",
                    "secure" => true,
                    "httponly" => true,
                    "samesite" => "Strict"
                ]
            );
            // Language
            $variants = ['en', 'ru'];
            $language = in_array($_POST['language'], $variants) ? $_POST['language'] : '';
            $current_language = $language ?: $current_language;
            setcookie(
                'language',
                $language,
                [
                    "expires" => $time * ($language ? 1 : -1),
                    "path" => "/",
                    "domain" => "",
                    "secure" => true,
                    "httponly" => true,
                    "samesite" => "Strict"
                ]
            );

            $posted_input = trim((string) ($_POST['current_account_id'] ?? ''));
            $posted_radio = trim((string) ($_POST['current_account_radio'] ?? ''));
            $current_account_input = strtoupper($posted_input);
            if ($current_account_input === '') {
                $current_account_input = $posted_radio;
            }

            if ($current_account_input === '') {
                $this->CurrentUser->setCurrentAccountId(null);
                $current_account_value = $this->CurrentUser->getCurrentAccountId();
            } elseif (!$this->CurrentUser->setCurrentAccountId($current_account_input)) {
                $current_account_error = $Translator->trans('preferences.current_account.errors.invalid_account_id');
                $current_account_value = $current_account_input;
                $current_account_input_value = $current_account_input;
            } else {
                $current_account_value = $this->CurrentUser->getCurrentAccountId();
                if ($this->CurrentUser->isAuthorized()) {
                    $current_account_input_value = '';
                } else {
                    $current_account_input_value = $current_account_value;
                }
            }

            if ($current_account_error === null) {
                SimpleRouter::response()->redirect('/preferences', 302);
            }
        }

        $Template = $this->Twig->load('preferences.twig');

        $Account = null;
        $contacts_count = null;
        $account_id = $this->CurrentUser->getAccountId();
        if ($account_id) {
            $Account = $this->BSN->makeAccountById($account_id);
            $contacts_count = count(($this->Container->get(ContactsManager::class))->getContacts($account_id));
        }

        $current_account_options = $this->buildCurrentAccountOptions();

        $this->Twig->addGlobal('session', $_SESSION);

        return $Template->render([
            'current_value' => $this->default_viewer,
            'current_language' => $current_language,
            'account' => $Account ? $Account->jsonSerialize() : [],
            'contacts_count' => $contacts_count,
            'current_account_value' => $current_account_value,
            'current_account_input_value' => $current_account_input_value,
            'current_account_options' => $current_account_options,
            'current_account_error' => $current_account_error,
        ]);
    }

    private function buildCurrentAccountOptions(): array
    {
        $options = [];
        if (!$this->CurrentUser->isAuthorized()) {
            return $options;
        }

        $account_id = $this->CurrentUser->getAccountId();
        if ($account_id) {
            $Account = $this->BSN->makeAccountById($account_id);
            $options[$account_id] = array_merge(
                $Account->jsonSerialize(),
                ['source' => 'self']
            );
        }

        foreach ($this->getOwnedAccounts($account_id) as $Account) {
            $options[$Account->getId()] = array_merge(
                $Account->jsonSerialize(),
                ['source' => 'owned']
            );
        }

        foreach ($this->CurrentUser->getCurrentAccountHistory() as $history_account_id) {
            if (!BSN::validateStellarAccountIdFormat($history_account_id)) {
                continue;
            }
            if (array_key_exists($history_account_id, $options)) {
                continue;
            }
            $Account = $this->BSN->makeAccountById($history_account_id);
            $options[$history_account_id] = array_merge(
                $Account->jsonSerialize(),
                ['source' => 'history']
            );
        }

        return array_values($options);
    }

    private function getOwnedAccounts(?string $owner_id): array
    {
        if (!$owner_id) {
            return [];
        }

        $OwnerTag = Tag::fromName('Owner');
        $OwnershipFullTag = Tag::fromName('OwnershipFull');
        $owned = [];
        foreach ($this->BSN->getAccounts() as $Account) {
            $owners = $Account->getOutcomeLinks($OwnerTag);
            if (count($owners) !== 1) {
                continue;
            }

            $Owner = $owners[0];
            if ($Owner->getId() !== $owner_id || $Account->getId() === $owner_id) {
                continue;
            }

            foreach ($Owner->getOutcomeLinks($OwnershipFullTag) as $OutcomeLink) {
                if ($OutcomeLink->getId() === $Account->getId()) {
                    $owned[$Account->getId()] = $Account;
                    break;
                }
            }
        }

        return array_values($owned);
    }
}
