<?php
namespace Montelibero\BSN;

use DI\Container;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\LoginController;
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
            $Tag = $this->BSN->findTagByName($q);
            if ($Tag) {
                SimpleRouter::response()->redirect(SimpleRouter::getUrl('tag', ['id' => $Tag->getName()]));
                return null;
            }
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

        if (array_key_exists('current_account', $_GET)) {
            $this->handleCurrentAccountPreference((string) $_GET['current_account']);
            return null;
        }

        if (array_key_exists('show_unknown_tags', $_GET)) {
            $this->handleShowUnknownTagsPreference((string) $_GET['show_unknown_tags']);
            return null;
        }

        $current_account_error = null;
        $current_account_value = $this->CurrentUser->getCurrentAccountId();
        $current_account_input_value = $this->CurrentUser->isAuthorized() ? '' : $current_account_value;
        $current_language = $Translator->getLocale();
        $current_show_unknown_tags = $this->CurrentUser->getShowUnknownTags();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $current_account_action = trim((string) ($_POST['current_account_action'] ?? ''));
            if ($current_account_action === 'reset') {
                $reset_account_id = $this->CurrentUser->isAuthorized()
                    ? $this->CurrentUser->getAccountId()
                    : null;
                $this->CurrentUser->setCurrentAccountId($reset_account_id);
                SimpleRouter::response()->redirect($this->resolvePreferencesReturnTo('/'), 302);
                return null;
            }

            $time = time() + (6 * 30 * 24 * 60 * 60); // 6 months
            $variants = ['this', 'eurmtl', 'brainbox'];
            $viewer_input = (string) ($_POST['viewer'] ?? '');
            $viewer = in_array($viewer_input, $variants, true) ? $viewer_input : '';
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
            $language_input = (string) ($_POST['language'] ?? '');
            $language = in_array($language_input, $variants, true) ? $language_input : '';
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
            // Unknown tags visibility
            $show_unknown_tags = ($_POST['show_unknown_tags'] ?? null) === 'yes';
            $current_show_unknown_tags = $show_unknown_tags;
            $this->setShowUnknownTagsCookie($show_unknown_tags);

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
            'current_show_unknown_tags' => $current_show_unknown_tags,
            'account' => $Account ? $Account->jsonSerialize() : [],
            'contacts_count' => $contacts_count,
            'current_account_value' => $current_account_value,
            'current_account_input_value' => $current_account_input_value,
            'current_account_options' => $current_account_options,
            'current_account_error' => $current_account_error,
        ]);
    }

    public function WhoYouAre(): string
    {
        $Template = $this->Twig->load('who_you_are.twig');
        $return_to = $this->resolveReturnToFromRequest('/');
        $current_account_id = strtoupper(trim((string) ($_GET['current_account'] ?? '')));

        if ($current_account_id === '') {
            $current_account_id = $this->CurrentUser->getCurrentAccountId() ?? '';
        }

        return $Template->render([
            'return_to' => $return_to,
            'current_account_value' => $current_account_id,
            'current_account_error' => $this->resolveWhoYouAreError(),
        ]);
    }

    private function buildCurrentAccountOptions(): array
    {
        $options = [];
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

        $history_account_ids = $this->CurrentUser->getCurrentAccountHistory();
        $current_account_id = $this->CurrentUser->getCurrentAccountId();
        if (
            $current_account_id
            && $current_account_id !== $account_id
            && !in_array($current_account_id, $history_account_ids, true)
        ) {
            array_unshift($history_account_ids, $current_account_id);
        }

        foreach ($history_account_ids as $history_account_id) {
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

    private function resolvePreferencesReturnTo(string $fallback = '/preferences'): string
    {
        return LoginController::normalizeReturnTo($_POST['return_to'] ?? $_SERVER['REQUEST_URI'] ?? null, $fallback);
    }

    private function handleCurrentAccountPreference(string $current_account): void
    {
        $current_account = strtoupper(trim($current_account));
        $return_to = $this->resolveReturnToFromRequest('/');

        if ($current_account === '' || !$this->CurrentUser->setCurrentAccountId($current_account)) {
            $redirect_url = '/who_you_are?error=invalid_account_id&return_to=' . urlencode($return_to);
            if ($current_account !== '') {
                $redirect_url .= '&current_account=' . urlencode($current_account);
            }
            SimpleRouter::response()->redirect($redirect_url, 302);
            return;
        }

        SimpleRouter::response()->redirect($return_to, 302);
    }

    private function resolveWhoYouAreError(): ?string
    {
        if (($_GET['error'] ?? null) !== 'invalid_account_id') {
            return null;
        }

        return $this->Container
            ->get(Translator::class)
            ->trans('preferences.current_account.errors.invalid_account_id');
    }

    private function resolveReturnToFromRequest(string $fallback = '/'): string
    {
        foreach ([$_GET['return_to'] ?? null, $_POST['return_to'] ?? null, $_SERVER['HTTP_REFERER'] ?? null] as $candidate) {
            $return_to = $this->normalizeReturnTo($candidate, '');
            if ($return_to !== '') {
                return $return_to;
            }
        }

        return $this->normalizeReturnTo($fallback, '/');
    }

    private function normalizeReturnTo(?string $return_to, string $fallback = '/'): string
    {
        $return_to = LoginController::normalizeReturnTo($return_to, $fallback);
        if (preg_match('~^/(who_you_are|preferences)(?:[/?#]|$)~', $return_to)) {
            return LoginController::normalizeReturnTo($fallback, '/');
        }

        return $return_to;
    }

    private function handleShowUnknownTagsPreference(string $value): void
    {
        $return_to = $this->resolveSameHostRefererPath();
        if ($return_to === null || !in_array($value, ['yes', 'no'], true)) {
            SimpleRouter::response()->redirect('/preferences', 302);
            return;
        }

        if ($value === 'yes') {
            $this->setShowUnknownTagsCookie(true);
        } else {
            $this->setShowUnknownTagsCookie(false);
        }

        SimpleRouter::response()->redirect($return_to, 302);
    }

    private function setShowUnknownTagsCookie(bool $show): void
    {
        setcookie(
            'show_unknown_tags',
            $show ? 'yes' : '',
            [
                "expires" => $show ? time() + (6 * 30 * 24 * 60 * 60) : time() - 3600,
                "path" => "/",
                "domain" => "",
                "secure" => true,
                "httponly" => true,
                "samesite" => "Strict"
            ]
        );
    }

    private function resolveSameHostRefererPath(): ?string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer === '') {
            return null;
        }

        $referer_host = parse_url($referer, PHP_URL_HOST);
        if (!is_string($referer_host) || $referer_host === '') {
            return null;
        }

        $referer_port = parse_url($referer, PHP_URL_PORT);
        if (is_int($referer_port)) {
            $referer_host .= ':' . $referer_port;
        }

        if (strcasecmp($referer_host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
            return null;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) {
            $path = '/';
        }

        $query = parse_url($referer, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        $fragment = parse_url($referer, PHP_URL_FRAGMENT);
        if (is_string($fragment) && $fragment !== '') {
            $path .= '#' . $fragment;
        }

        return $path;
    }
}
