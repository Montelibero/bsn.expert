<?php

namespace Montelibero\BSN;

use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Relations\Member;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\StellarSDK;
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
    private StellarSDK $Stellar;

    public function __construct(BSN $BSN, AccountsManager $AccountsManager, Environment $Twig, StellarSDK $Stellar)
    {
        $this->BSN = $BSN;
        $this->AccountsManager = $AccountsManager;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;

        if (isset($_COOKIE['default_viewer']) && $_COOKIE['default_viewer']) {
            $this->default_viewer = $_COOKIE['default_viewer'];
        }
    }

    public function Index(): ?string
    {
        $Template = $this->Twig->load('index.twig');
        return $Template->render([
            'accounts_count' => $this->BSN->getAccountsCount(),
            'adopters_count' => count((new AccountsController($this->BSN, $this->Twig, $this->Stellar))->getAdopters()),
        ]);
    }

    public function Search(): ?string
    {
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        if (BSN::validateStellarAccountIdFormat($q)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('account', ['id' => $q]));
        }
        if ($account_id = $this->AccountsManager->fetchAccountIdByUsername($q)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('account', ['id' => $account_id]));
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
        }

        $Template = $this->Twig->load('search.twig');
        return $Template->render([
            'q' => $q,
        ]);
    }

    public static function semantic_sort_keys(array &$data, array $sort_example): void
    {
        uksort($data, function ($a, $b) use ($sort_example) {
            $indexA = array_search($a, $sort_example);
            $indexB = array_search($b, $sort_example);

            // Если оба ключа есть в массиве сортировки
            if ($indexA !== false && $indexB !== false) {
                return $indexA - $indexB;
            } // Если ключ A в массиве сортировки, а B нет
            elseif ($indexA !== false) {
                return -1;
            } // Если ключ B в массиве сортировки, а A нет
            elseif ($indexB !== false) {
                return 1;
            } // Если ни один из ключей не в массиве сортировки, сортируем их по алфавиту
            else {
                return $a <=> $b;
            }
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
