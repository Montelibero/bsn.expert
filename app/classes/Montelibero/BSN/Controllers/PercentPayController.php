<?php

namespace Montelibero\BSN\Controllers;

use DI\Container;
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
        $calc_mode = $_GET['calc_mode'] ?? 'percent';
        if (!in_array($calc_mode, ['percent', 'amount'], true)) {
            $calc_mode = 'percent';
        }
        $percent = $_GET['percent'] ?? '';
        $percent = str_replace([' ', ','], ['', '.'], $percent);
        if (!is_numeric($percent) || $percent == 0 || $percent == '' || $percent < 0) {
            $percent = null;
        }
        $amount = $_GET['amount'] ?? '';
        $amount = str_replace([' ', ','], ['', '.'], $amount);
        if (!is_numeric($amount) || $amount == 0 || $amount == '' || $amount < 0) {
            $amount = null;
        }
        $balance_limit = $_GET['balance_limit'] ?? '0';
        $balance_limit = str_replace([' ', ','], ['', '.'], $balance_limit);
        if (!is_numeric($balance_limit) || $balance_limit === '' || $balance_limit < 0) {
            $balance_limit = '0';
        }
        $distribution_input_valid = $calc_mode === 'amount' ? $amount !== null : $percent !== null;
        $payer_account = $_GET['payer_account'] ?? null;
        if (!BSN::validateStellarAccountIdFormat($payer_account)) {
            $payer_account = null;
        }
        $memo = $_GET['memo'] ?? null;

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
                    $pt_issuer = $pt['issuer'];
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

        $accounts = [];
        $sum_balance = "0.0000000";
        $sum_to_pay = "0.0000000";
        $sum_balance_trustline = "0.0000000";
        $sum_to_pay_trustline = "0.0000000";
        $has_eligible_payment_recipients = false;
        $has_missing_payment_trustlines = false;
        $filtered_out_accounts_count = 0;
        $filtered_out_balance_sum = "0.0000000";
        $payment_source_account = $payer_account ?: $asset_issuer;
        $payment_source_available_balance = null;
        $payment_source_insufficient_balance = false;
        $planned_payment_total = "0.0000000";
        if ($asset_issuer && $asset_code && $distribution_input_valid) {
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
                    foreach ($Account->getBalances()->toArray() as $Balance) {
                        if (
                            $Balance->getAssetType() !== Asset::TYPE_NATIVE
                            && $Balance->getAssetIssuer() === $asset_issuer
                            && $Balance->getAssetCode() === $asset_code
                            && (float) $Balance->getBalance() > 0
                        ) {
                            if (bccomp($Balance->getBalance(), $balance_limit, 7) < 0) {
                                $filtered_out_accounts_count++;
                                $filtered_out_balance_sum = bcadd($filtered_out_balance_sum, $Balance->getBalance(), 7);
                                continue;
                            }
                            $account['balance'] = $Balance->getBalance();
                            $account['has_payment_trustline'] = $this->hasTrustlineForAsset(
                                $Account->getBalances(),
                                $payment_token['code'],
                                $payment_token['issuer']
                            );
                            $accounts[] = $account;
                        }
                    }
                }
                $Accounts = $Accounts->getNextPage();
            } while ($Accounts->getAccounts()->count());
        }

        foreach ($accounts as & $account) {
            $Account = $this->BSN->makeAccountById($account['id']);
            $account += $Account->jsonSerialize();
            $sum_balance = bcadd($sum_balance, $account['balance'], 7);
        }
        unset($account);

        if ($calc_mode === 'amount') {
            $sum_to_pay = $this->distributeProportionalAmount(
                $accounts,
                $amount ?? "0.0000000",
                $sum_balance,
                'to_pay'
            );
        } else {
            foreach ($accounts as & $account) {
                $account['to_pay'] = bcmul($account['balance'], bcdiv($percent, "100", 7), 7);
                if ((float) $account['to_pay'] === 0.0) {
                    $account['to_pay'] = null;
                } else {
                    $sum_to_pay = bcadd($sum_to_pay, $account['to_pay'], 7);
                }
            }
            unset($account);
        }

        foreach ($accounts as & $account) {
            if ($account['has_payment_trustline'] && $account['to_pay']) {
                $sum_balance_trustline = bcadd($sum_balance_trustline, $account['balance'], 7);
                $has_eligible_payment_recipients = true;
            } elseif ($account['to_pay']) {
                $has_missing_payment_trustlines = true;
            }
        }
        unset($account);

        if ($has_eligible_payment_recipients) {
            $remaining_to_pay_trustline = $sum_to_pay;
            $remaining_balance_trustline = $sum_balance_trustline;
            foreach ($accounts as & $account) {
                if (!$account['has_payment_trustline'] || !$account['to_pay']) {
                    $account['to_pay_trustline'] = null;
                    continue;
                }

                if (bccomp($remaining_balance_trustline, $account['balance'], 7) === 0) {
                    $account['to_pay_trustline'] = $remaining_to_pay_trustline;
                } else {
                    $account['to_pay_trustline'] = bcdiv(
                        bcmul($sum_to_pay, $account['balance'], 16),
                        $sum_balance_trustline,
                        7
                    );
                }

                $sum_to_pay_trustline = bcadd($sum_to_pay_trustline, $account['to_pay_trustline'], 7);
                $remaining_to_pay_trustline = bcsub($remaining_to_pay_trustline, $account['to_pay_trustline'], 7);
                $remaining_balance_trustline = bcsub($remaining_balance_trustline, $account['balance'], 7);
            }
            unset($account);
        }

        if ($accounts) {
            $planned_payment_total = $has_eligible_payment_recipients ? $sum_to_pay_trustline : $sum_to_pay;
            if (
                bccomp($planned_payment_total, "0", 7) > 0
                && !(
                    $payment_source_account === $payment_token['issuer']
                    && $payment_token['issuer'] !== null
                )
            ) {
                $PaymentSource = $this->Stellar->requestAccount($payment_source_account);
                $payment_source_available_balance = $this->getAssetBalance(
                    $PaymentSource->getBalances(),
                    $payment_token['code'],
                    $payment_token['issuer']
                );
                $payment_source_insufficient_balance = bccomp(
                    $payment_source_available_balance,
                    $planned_payment_total,
                    7
                ) < 0;
            }
        }

        $signing_forms = [];
        if ($accounts && $has_eligible_payment_recipients) {
            $StellarAccount = $this->Stellar->requestAccount($payer_account ?: $asset_issuer);
            $Asset = Asset::createNonNativeAsset($payment_token['code'], $payment_token['issuer']);
            $operations = [];
            $operations_limit = 100;
            foreach ($accounts as $account) {
                if (!$account['to_pay_trustline']) {
                    continue;
                }
                $Operation = new PaymentOperationBuilder($account['id'], $Asset, $account['to_pay_trustline']);
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
            'calc_mode' => $calc_mode,
            'percent' => $percent,
            'amount' => $amount,
            'balance_limit' => $balance_limit,
            'payment_token_options' => $payment_token_options,
            'payment_token' => $payment_token,
            'payer_account' => $payer_account,
            'memo' => $memo,
            'accounts' => $accounts,
            'sum_balance' => $sum_balance,
            'sum_to_pay' => $sum_to_pay,
            'sum_to_pay_trustline' => $sum_to_pay_trustline,
            'has_eligible_payment_recipients' => $has_eligible_payment_recipients,
            'has_missing_payment_trustlines' => $has_missing_payment_trustlines,
            'filtered_out_accounts_count' => $filtered_out_accounts_count,
            'filtered_out_balance_sum' => $filtered_out_balance_sum,
            'payment_source_account' => $payment_source_account,
            'payment_source_available_balance' => $payment_source_available_balance,
            'payment_source_insufficient_balance' => $payment_source_insufficient_balance,
            'planned_payment_total' => $planned_payment_total,
            'signing_forms' => $signing_forms,
        ]);
    }

    private function hasTrustlineForAsset(iterable $balances, string $asset_code, string $asset_issuer): bool
    {
        /** @var AccountBalanceResponse $Balance */
        foreach ($balances as $Balance) {
            if (
                $Balance->getAssetType() !== Asset::TYPE_NATIVE
                && $Balance->getAssetCode() === $asset_code
                && $Balance->getAssetIssuer() === $asset_issuer
            ) {
                return true;
            }
        }

        return false;
    }

    private function getAssetBalance(iterable $balances, string $asset_code, string $asset_issuer): string
    {
        /** @var AccountBalanceResponse $Balance */
        foreach ($balances as $Balance) {
            if (
                $Balance->getAssetType() !== Asset::TYPE_NATIVE
                && $Balance->getAssetCode() === $asset_code
                && $Balance->getAssetIssuer() === $asset_issuer
            ) {
                return $Balance->getBalance();
            }
        }

        return "0.0000000";
    }

    private function distributeProportionalAmount(
        array &$accounts,
        string $total_amount,
        string $balance_sum,
        string $field_name
    ): string {
        $distributed_total = "0.0000000";
        if (bccomp($total_amount, "0", 7) <= 0 || bccomp($balance_sum, "0", 7) <= 0) {
            foreach ($accounts as & $account) {
                $account[$field_name] = null;
            }
            unset($account);

            return $distributed_total;
        }

        $remaining_amount = $total_amount;
        $remaining_balance = $balance_sum;
        foreach ($accounts as & $account) {
            if (bccomp($remaining_balance, $account['balance'], 7) === 0) {
                $amount = $remaining_amount;
            } else {
                $amount = bcdiv(
                    bcmul($total_amount, $account['balance'], 16),
                    $balance_sum,
                    7
                );
            }

            if (bccomp($amount, "0", 7) === 0) {
                $account[$field_name] = null;
            } else {
                $account[$field_name] = $amount;
                $distributed_total = bcadd($distributed_total, $amount, 7);
            }
            $remaining_amount = bcsub($remaining_amount, $amount, 7);
            $remaining_balance = bcsub($remaining_balance, $account['balance'], 7);
        }
        unset($account);

        return $distributed_total;
    }
}
