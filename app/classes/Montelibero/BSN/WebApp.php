<?php
namespace Montelibero\BSN;

use DI\Container;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\TokensController;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class WebApp
{
    private BSN $BSN;
    private AccountsManager $AccountsManager;
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
    private Container $Container;

    public function __construct(BSN $BSN, AccountsManager $AccountsManager, Environment $Twig, Container $Container)
    {
        $this->BSN = $BSN;
        $this->AccountsManager = $AccountsManager;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);


        $this->Container = $Container;

        if (isset($_COOKIE['default_viewer']) && $_COOKIE['default_viewer']) {
            $this->default_viewer = $_COOKIE['default_viewer'];
        }
    }

    public function Index(): ?string
    {
        $Template = $this->Twig->load('index.twig');
        return $Template->render([
            'accounts_count' => $this->BSN->getAccountsCount(),
            'adopters_count' => count(($this->Container->get(AccountsController::class))->getAdopters()),
        ]);
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
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('transaction_page', ['tx_hash' => $q]));
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
            SimpleRouter::response()->redirect('/preferences', 302);
        }

        $Template = $this->Twig->load('preferences.twig');
        return $Template->render([
            'current_value' => $this->default_viewer,
        ]);
    }
}
