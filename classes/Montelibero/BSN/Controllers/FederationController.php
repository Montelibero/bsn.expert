<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class FederationController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private AccountsManager $AccountsManager;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, AccountsManager $AccountsManager)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;

        $this->AccountsManager = $AccountsManager;
    }

    public function Federation(): ?string
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        $type = $_GET['type'] ?? null;
        $q = $_GET['q'] ?? '';

        if ($type === 'name') {
            if (preg_match('/^(?<name>[a-zA-Z0-9_]+)\*bsn\.expert$/i', $q, $matches)) {
                $account_id = $this->AccountsManager->fetchAccountIdByUsername($matches['name']);
                if ($account_id) {
                    print json_encode([
                        'stellar_address' => $this->BSN->makeAccountById($account_id)->getUsername() . '*bsn.expert',
                        'account_id' => $account_id,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                } else {
                    print json_encode([
                        'error' => 'Account not found',
                        'name' => $matches['name'],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    SimpleRouter::response()->httpCode(404);
                }
            } else {
                print json_encode([
                    'error' => 'Wrong name format',
                    'name' => $q,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                SimpleRouter::response()->httpCode(501);
            }
        } else if ($type === 'forward') {
            print json_encode([
                'error' => 'Not implemented',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            SimpleRouter::response()->httpCode(501);
        } else if ($type === 'id') {
            if (!$this->BSN::validateStellarAccountIdFormat($q)) {
                print json_encode([
                    'error' => 'Wrong account id format.',
                    'account_id' => $q,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                SimpleRouter::response()->httpCode(400);
            } else {
                $Account = $this->BSN->makeAccountById($q);
                if ($username = $Account->getUsername()) {
                    print json_encode([
                        'stellar_address' => $username . '*bsn.expert',
                        'account_id' => $q,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                } else {
                    print json_encode([
                        'error' => 'Account not found',
                        'name' => $q,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    SimpleRouter::response()->httpCode(404);
                }
            }
            print json_encode([
                'error' => 'Not implemented',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            SimpleRouter::response()->httpCode(501);
        } else if ($type === 'txid') { // returns the federation record of the sender of the transaction if known by the server.
            print json_encode([
                'error' => 'Not implemented',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            SimpleRouter::response()->httpCode(501);
        } else {
            print json_encode([
                'error' => 'Missing or wrong type.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            SimpleRouter::response()->httpCode(400);
        }

        return null;
    }
}
