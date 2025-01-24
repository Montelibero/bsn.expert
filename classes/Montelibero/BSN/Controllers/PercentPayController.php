<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Twig\Environment;

class PercentPayController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(BSN $BSM, Environment $Twig, StellarSDK $Stellar)
    {
        $this->BSN = $BSM;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        
        $this->Stellar = $Stellar;
    }

    public function PercentPay(): ?string
    {
        if (empty($_SERVER['QUERY_STRING']) && !empty($_COOKIE['percent_pay'])) {
            $percent_pay = json_decode($_COOKIE['percent_pay'], true);
            if ($percent_pay) {
                SimpleRouter::response()->redirect('/tools/percent_pay?' . http_build_query($percent_pay), 302);
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
