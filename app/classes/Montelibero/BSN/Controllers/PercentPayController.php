<?php

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use phpseclib3\Math\BigInteger;
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
    private Container $Container;

    public function __construct(BSN $BSM, Environment $Twig, StellarSDK $Stellar, Container $Container)
    {
        $this->BSN = $BSM;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        
        $this->Stellar = $Stellar;
        $this->Container = $Container;
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
        if ($asset_code && !preg_match('/[0-1a-zA-Z]{1,12}/', $asset_code)) {
            $asset_code = null;
        }
        $percent = $_GET['percent'] ?? '';
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
        $sum_balance = "0.0000000";
        $sum_to_pay = "0.0000000";
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
            $sum_balance = bcadd($sum_balance, $account['balance'], 7);

            $account['to_pay'] = bcmul($account['balance'], bcdiv($percent, "100", 7), 7);
            if ((float) $account['to_pay'] === 0.0) {
                $account['to_pay'] = null;
            } else {
                $sum_to_pay = bcadd($sum_to_pay, $account['to_pay'], 7);
            }
        }
        unset($account);

        $payment_token_options = [
            [
                'code' => 'EURMTL',
                'issuer' => 'GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V',
            ],
            [
                'code' => 'USDM',
                'issuer' => 'GDHDC4GBNPMENZAOBB4NCQ25TGZPDRK6ZGWUGSI22TVFATOLRPSUUSDM',
            ],
            [
                'code' => 'USDC',
                'issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            ],
            [
                'code' => 'SATSMTL',
                'issuer' => 'GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V',
            ],
        ];
        $payment_token = $payment_token_options[0];
        if ($_GET['payment_token'] ?? null) {
            $pt_code = $_GET['payment_token'];
            $pt_issuer = null;
            if (str_contains($_GET['payment_token'], "-")) {
                [$pt_code, $pt_issuer] = explode('-', $_GET['payment_token']);
            }
            if ($this->BSN::validateTokenNameFormat($pt_code)) {
                if (
                    !$pt_issuer
                    && $pt = $this->Container->get(TokensController::class)->getKnownTokenByCode($pt_code)
                ) {
                    $pt_issuer = $pt['issuer'];;
                }
            }
            if ($pt_code && $pt_issuer) {
                $payment_token = [
                    'code' => $pt_code,
                    'issuer' => $pt_issuer,
                ];
            }
        }
        foreach ($payment_token_options as $pt_option) {
            if ($pt_option['code'] === $payment_token['code'] && $pt_option['issuer'] === $payment_token['issuer']) {
                $payment_token['is_standard'] = true;
                break;
            }
        }
        $signing_forms = [];
        if ($accounts) {
            $StellarAccount = $this->Stellar->requestAccount($payer_account ?: $asset_issuer);
            $Asset = Asset::createNonNativeAsset($payment_token['code'], $payment_token['issuer']);
            $operations = [];
            $operations_limit = 100;
            foreach ($accounts as $account) {
                if (!$account['to_pay']) {
                    continue;
                }
                $Operation = new PaymentOperationBuilder($account['id'], $Asset, $account['to_pay']);
                $operations[] = $Operation->build();
            }
            foreach (array_chunk($operations, $operations_limit) as $bulk_of_operations) {
                $Transaction = new TransactionBuilder($StellarAccount);
                if ($memo) {
                    $Transaction->addMemo(Memo::text($memo));
                }
                $Transaction->setMaxOperationFee(10000);
                $Transaction->addOperations($bulk_of_operations);
                $signing_forms[] = $this->Container->get(SignController::class)->SignTransaction(
                    $Transaction->build()->toEnvelopeXdrBase64()
                );

            }

        }

        $Template = $this->Twig->load('tools_percent_pay.twig');
        return $Template->render([
            'asset_issuer' => $asset_issuer,
            'asset_code' => $asset_code,
            'percent' => $percent,
            'payment_token_options' => $payment_token_options,
            'payment_token' => $payment_token,
            'payer_account' => $payer_account,
            'memo' => $memo,
            'accounts' => $accounts,
            'sum_balance' => $sum_balance,
            'sum_to_pay' => $sum_to_pay,
            'signing_forms' => $signing_forms,
        ]);
    }
}
