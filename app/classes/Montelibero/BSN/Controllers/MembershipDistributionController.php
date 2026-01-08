<?php
namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\ClawbackOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\SetTrustLineFlagsOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Twig\Environment;

class MembershipDistributionController
{
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(Environment $Twig, StellarSDK $Stellar)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        
        $this->Stellar = $Stellar;
    }

    public function MtlaMembershipDistribution(): string
    {
        $account_main = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';
        $account_secretary = 'GDGC46H4MQKRW3TZTNCWUU6R2C7IPXGN7HQLZBJTNQO6TW7ZOS6MSECR';

        $csrf_token = md5(session_id() . 'membership_distribution');

        /** @var AssetTypeCreditAlphanum[] $assets */
        $assets = [
            "MTLAP" => Asset::createNonNativeAsset("MTLAP", $account_main),
            "MTLAC" => Asset::createNonNativeAsset("MTLAC", $account_main),

        ];

        $error = '';
        $transaction = '';
        $accounts = [];
        if (($_POST ?? []) && ($_POST['csrf_token'] ?? null) === $csrf_token && ($_POST['accounts'] ?? '')) {
            // Нормализуем переносы строк и разбиваем на массив
            $lines = preg_split('/\r\n|\r|\n/', $_POST['accounts']);
            // Удаляем пустые строки и пробелы
            $lines = array_filter(array_map('trim', $lines));
            foreach ($lines as $line) {
                if (!$line) {
                    continue;
                }
                if (preg_match('/^(?<account>\w+)(?:\s+(?<amount>\d+))?(?:\s+(?<token>MTLA[PC]))?$/i', $line, $m)) {
                    $m['account'] = strtoupper($m['account']);
                    $m['amount'] = (isset($m['amount']) && is_numeric($m['amount'])) ? $m['amount'] : 1;
                    $m['token'] = strtoupper(isset($m['token']) && $m['token'] ? $m['token'] : 'MTLAP');
                    if (!BSN::validateStellarAccountIdFormat($m['account'])) {
                        $error .= 'Invalid account: ' . $m['account'] . "\n";
                    } elseif ($m['amount'] == 0 || abs($m['amount']) > 5) {
                        $error .= 'Amount must be between -5 and -1 or between 1 and 5: ' . $m['amount'] . "\n";
                    } elseif (
                        ($Account = $this->Stellar->requestAccount($m['account']))
                        && !$this->hasAsset($Account, $assets[$m['token']])
                    ) {
                        $error .= 'Account ' . $m['account'] . ' does not have ' . $m['token'] . ' asset!' . "\n";
                    } else {
                        $accounts[$m['account']] = [
                            'account' => $m['account'],
                            'amount' => $m['amount'],
                            'token' => $m['token'],
                        ];
                    }
                } else {
                    $error .= 'Error account format: ' . $line . "\n";
                }
            }
            if (!count($accounts)) {
                $error .= "Нет аккаунтов, куда это вот всё\n";
            }
            $memo = $_POST['memo'] ?? '';
            if (strlen($memo) > 28) {
                $error .= "Длина мемо не может быть больше 28 байт\n";
            }
            $seq_num = $_POST['seq_num'] ?? '';
            if ($seq_num !== '' && !preg_match('/^\d+$/', $seq_num)) {
                $error .= "Seq num должен быть числом, ну вы чего\n";
            }


            if (!$error) {
                $StellarAccount = $this->Stellar->requestAccount($account_secretary);
                /** @var AbstractOperation[] $operations */
                $operations = [];
                foreach ($accounts as $data) {
                    $Account = $this->Stellar->requestAccount($data['account']);

                    if ($data['amount'] > 0) {
                        $Operation = new SetTrustLineFlagsOperationBuilder($data['account'], $assets[$data['token']], 0, 1);
                        $Operation->setSourceAccount($account_main);
                        $operations[] = $Operation->build();

                        $Operation = new PaymentOperationBuilder($data['account'], $assets[$data['token']], $data['amount']);
                        $operations[] = $Operation->build();

                        $Operation = new SetTrustLineFlagsOperationBuilder($data['account'], $assets[$data['token']], 1, 0);
                        $Operation->setSourceAccount($account_main);
                        $operations[] = $Operation->build();
                    } else {
                        $Operation = new ClawbackOperationBuilder(
                            $assets[$data['token']],
                            MuxedAccount::fromAccountId($data['account']),
                            abs($data['amount'])
                        );
                        $Operation->setSourceAccount($account_main);
                        $operations[] = $Operation->build();
                    }
                }
                $Transaction = new Transaction(
                    $StellarAccount->getMuxedAccount(),
                    $seq_num ? new BigInteger($seq_num) : $StellarAccount->getIncrementedSequenceNumber(),
                    $operations,
                    $memo ? Memo::text($memo) : Memo::none(),
                    null,
                    count($operations) * 10000,
                );

                $transaction = $Transaction->toEnvelopeXdrBase64();
            }
        }

        $sign_tools_url = null;
        if ($transaction) {
            $sign_tools_url = $this->pushTransactionToEurmtl($transaction, $memo ?: 'Membership Distribution');
        }

        return $this->Twig->render('tools_mtla_membership_distribution.twig', [
            'csrf_token' => $csrf_token,
            'accounts' => $_POST['accounts'] ?? '',
            'source_account' => $_POST['source_account'] ?? 'secretary',
            'memo' => $_POST['memo'] ?? 'Membership Distribution',
            'seq_num' => $_POST['seq_num'] ?? '',
            'error' => $error,
            'transaction' => $transaction,
            'sign_tools_url' => $sign_tools_url,
        ]);
    }

    private function hasAsset(AccountResponse $Account, AssetTypeCreditAlphanum $AssetNeedle): bool
    {
        foreach ($Account->getBalances()->toArray() as $Asset) {
            if (
                ($Asset instanceof AccountBalanceResponse)
                && $Asset->getAssetType() === $AssetNeedle->getType()
                && $Asset->getAssetCode() === $AssetNeedle->getCode()
                && $Asset->getAssetIssuer() === $AssetNeedle->getIssuer()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $xdr
     * @param string $description
     * @return string
     * @deprecated
     */
    private function pushTransactionToEurmtl(string $xdr, string $description): string
    {
        return CommonController::pushTransactionToEurmtl($xdr, $description);
    }
}
