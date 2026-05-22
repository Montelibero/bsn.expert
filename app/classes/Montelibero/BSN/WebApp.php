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


        $this->Container = $Container;
        $this->CurrentUser = $CurrentUser;
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

        $current_language = $Translator->getLocale();
        $current_show_unknown_tags = $this->CurrentUser->getShowUnknownTags();
        $current_default_viewer = $this->resolveDefaultViewer();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $time = time() + (6 * 30 * 24 * 60 * 60); // 6 months
            $variants = ['this', 'eurmtl', 'brainbox'];
            $viewer_input = (string) ($_POST['viewer'] ?? '');
            $viewer = in_array($viewer_input, $variants, true) ? $viewer_input : '';
            $current_default_viewer = $viewer;
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

            SimpleRouter::response()->redirect('/preferences', 302);
        }

        $Template = $this->Twig->load('preferences.twig');

        return $Template->render([
            'current_value' => $current_default_viewer,
            'current_language' => $current_language,
            'current_show_unknown_tags' => $current_show_unknown_tags,
        ]);
    }

    private function resolveDefaultViewer(): ?string
    {
        $default_viewer = $_COOKIE['default_viewer'] ?? null;

        return is_string($default_viewer) && $default_viewer !== '' ? $default_viewer : null;
    }

    private function handleCurrentAccountPreference(string $current_account): void
    {
        $current_account = strtoupper(trim($current_account));
        $return_to = $this->resolveReturnToFromRequest('/');

        if ($current_account === '' || !$this->CurrentUser->setCurrentAccountId($current_account)) {
            $redirect_url = '/who_are_you?error=invalid_account_id&return_to=' . urlencode($return_to);
            if ($current_account !== '') {
                $redirect_url .= '&current_account=' . urlencode($current_account);
            }
            SimpleRouter::response()->redirect($redirect_url, 302);
            return;
        }

        if (!isset($_COOKIE[session_name()]) && $this->isCurrentAccountReturnTo($return_to)) {
            $return_to = $this->appendQueryParameters($return_to, ['current_account' => $current_account]);
        }

        SimpleRouter::response()->redirect($return_to, 302);
    }

    private function resolveReturnToFromRequest(string $fallback = '/'): string
    {
        return ReturnTo::getFromRequest($fallback, ['login', 'logout', 'who_are_you', 'preferences']);
    }

    private function appendQueryParameters(string $url, array $parameters): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        foreach ($parameters as $key => $value) {
            $query[$key] = $value;
        }

        $result = $parts['path'] ?? '/';
        if ($query) {
            $result .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $result .= '#' . $parts['fragment'];
        }

        return $result;
    }

    private function isCurrentAccountReturnTo(string $return_to): bool
    {
        return preg_match('~^/(?:editor(?:[/?#]|$)|accounts/[A-Z0-9]+/edit_tags(?:[/?#]|$)|tools/close_trustlines(?:[/?#]|$)|crowd(?:[/?#]|$))~', $return_to) === 1;
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
        $return_to = ReturnTo::normalize($_SERVER['HTTP_REFERER'] ?? null, '');
        return $return_to === '' ? null : $return_to;
    }
}
