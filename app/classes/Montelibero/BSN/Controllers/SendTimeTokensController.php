<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Twig\Environment;

class SendTimeTokensController
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

    public function MtlaSendTimeTokens(): string
    {
        $default_account = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';

        $csrf_token = md5(session_id() . 'send_time_tokens');

        $error = '';
        $transaction = '';

        $source_account = $_GET['source_account'] ?? $_POST['source_account'] ?? $default_account;

        if (($_POST ?? []) && ($_POST['csrf_token'] ?? null) === $csrf_token && ($_POST['targets'] ?? '')) {
            // Нормализуем переносы строк и разбиваем на массив
            $lines = preg_split('/\r\n|\r|\n/', $_POST['targets']);
            // Удаляем пустые строки и пробелы
            $lines = array_filter(array_map('trim', $lines));

            // Подгружаем связность токенов
            $tokens = $this->fetchTimeTokensDetails();
            $targets = [
                'issuer' => [],
            ];
            $to = null;
            foreach ($lines as $line) {
                if (!$line) {
                    continue;
                }
                if (BSN::validateStellarAccountIdFormat($line)) {
                    $to = $line;
                    continue;
                }
                if (preg_match(
                    '/^(?:(?<amount>\d+(?:\.\d+)?)\s+)?(?:(?<tg>@\w+)|(?<token>[\da-z]{1,14}))(?:\s+(?<amount2>\d+(?:\.\d+)?))?$/i',
                    $line,
                    $m
                )) {
                    if (!empty($m['amount']) && !empty($m['amount2']) && $m['amount'] !== $m['amount2']) {
                        $error .= 'Побойтесь бога, у вас число токенов и в начале и в конце строки: ' . $m['tg'] . "\n";
                        continue;
                    }
                    $amount = floatval($m['amount'] ?: ($m['amount2'] ?: 1));

                    if ($m['tg']) {
                        if (array_key_exists(strtolower($m['tg']), $tokens['tg'])) {
                            $targets[$to ?: 'issuer'][] = [
                                'token' => $tokens['tg'][strtolower($m['tg'])],
                                'amount' => $amount,
                            ];
                        } else {
                            $error .= 'Незнакомый телеграм юзернейм: ' . $m['tg'] . "\n";
                        }
                    } elseif ($m['token']) {
                        if (array_key_exists($m['token'], $tokens['token'])) {
                            $targets[$to ?: 'issuer'][] = [
                                'token' => $tokens['token'][$m['token']],
                                'amount' => $amount,
                            ];
                        } else {
                            $error .= 'Незнакомый код токена: ' . $m['token'] . "\n";
                        }
                    }
                }
            }
            // Проверяем набранное
            if (count($targets) < 2 && !count($targets['issuer'])) {
                $error .= "Нет целей перевода, куда это вот всё\n";
            }
            $memo = $_POST['memo'] ?? '';
            if (strlen($memo) > 28) {
                $error .= "Длина мемо не может быть больше 28 байт\n";
            }
            $seq_num = $_POST['seq_num'] ?? '';
            if ($seq_num !== '' && !preg_match('/^\d+$/', $seq_num)) {
                $error .= "Seq num должен быть числом, ну вы чего\n";
            }
            // Проверить наличие линий доверия у получателей
            foreach ($targets as $target => $items) {
                if ($target === 'issuer') {
                    continue;
                }
                $balances = $this->fetchBalances($target);
                foreach ($items as $item) {
                    if ($target === explode('-', $item['token'])[1]) {
                        continue;
                    }
                    if (!array_key_exists($item['token'], $balances)) {
                        $error .= "У {$target} нет линии доверия к токену {$item['token']}\n";
                    }
                }
            }
            // Проверить наличие нужного количества токенов у отправителя
            $sums = [];
            foreach ($targets as $target => $items) {
                foreach ($items as $item) {
                    if (!array_key_exists($item['token'], $sums)) {
                        $sums[$item['token']] = 0.0;
                    }
                    $sums[$item['token']] += $item['amount'];
                }
            }

            // Build assets collection
            /** @var AssetTypeCreditAlphanum[] $assets */
            $assets = [];
            foreach (array_keys($sums) as $token) {
                [$code, $issuer] = explode('-', $token);
                $assets[$token] = Asset::createNonNativeAsset($code, $issuer);
            }

            $balances = $this->fetchBalances($source_account);
            foreach ($sums as $token => $amount) {
                if ($amount > $balances[$token]) {
                    $error .= "На счету отправителя не хватает токена {$token} (нужно $amount есть {$balances[$token]})\n";
                }
            }

            if (!$error) {
                $StellarAccount = $this->Stellar->requestAccount($source_account);
                /** @var AbstractOperation[] $operations */
                $operations = [];
                foreach ($targets as $target => $items) {
                    foreach ($items as $item) {
                        $Asset = $assets[$item['token']];
                        $Operation = new PaymentOperationBuilder(
                            $target === 'issuer' ? $Asset->getIssuer() : $target,
                            $Asset,
                            $item['amount'],
                        );
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
            $sign_tools_url = $this->pushTransactionToEurmtl($transaction, $memo ?: 'Time Tokens Distribution');
        }

        return $this->Twig->render('tools_mtla_send_time_tokens.twig', [
            'csrf_token' => $csrf_token,
            'source_account' => $source_account,
            'targets' => $_POST['targets'] ?? '',
            'memo' => $_POST['memo'] ?? 'Time Tokens Distribution',
            'seq_num' => $_POST['seq_num'] ?? '',
            'error' => $error,
            'transaction' => $transaction,
            'sign_tools_url' => $sign_tools_url,
        ]);
    }

    private function fetchTimeTokensDetails(): array
    {
        $grist_response = \gristRequest(
            'https://montelibero.getgrist.com/api/docs/aYk6cpKAp9CDPJe51sP3AT/tables/Users/records',
            'GET'
        );
        $data = [
            'tg' => [],
            'token' => [],
            'doubles' => [],
        ];
        foreach ($grist_response['records'] as $item) {
            $fields = $item['fields'];
            if (
                empty($fields['TGID'])
                || empty($fields['Stellar'])
                || empty($fields['MTLAP'])
                || $fields['MTLAP'] == 0
                || empty($fields['Own_token'])
            ) {
                continue;
            }
            $token = $fields['Own_token'] . '-' . (empty($fields['Alt_issuer']) ? $fields['Stellar'] : $fields['Alt_issuer']);

            if (!empty($fields['Telegram'])) {
                $data['tg'][strtolower($fields['Telegram'])] = $token;
            }

            if (array_key_exists($fields['Own_token'], $data['token'])) {
                if (!array_key_exists($fields['Own_token'], $data['doubles'])) {
                    $data['doubles'][$fields['Own_token']] = [$data['token'][$fields['Own_token']]];
                }
                $data['doubles'][$fields['Own_token']][] = $token;
            }
            $data['token'][$fields['Own_token']] = $token;
        }

        return $data;
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

    private function getAmountOfXlm(AccountResponse $Account): float
    {
        foreach ($Account->getBalances()->toArray() as $Asset) {
            if (($Asset instanceof AccountBalanceResponse)
                && $Asset->getAssetType() === Asset::TYPE_NATIVE
            ) {
                return (float) $Asset->getBalance();
            }
        }

        return .0;
    }

    private function pushTransactionToEurmtl(string $xdr, string $description): string
    {
        $curl = curl_init('https://eurmtl.me/remote/add_transaction');

        $payload = json_encode([
            'tx_body' => $xdr,
            'tx_description' => $description
        ]);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $_ENV['EURMTL_KEY']
            ],
            CURLOPT_POSTFIELDS => $payload
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 201) {
            throw new \RuntimeException('Failed to push transaction to eurmtl.me. HTTP code: ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!isset($data['hash'])) {
            throw new \RuntimeException('Invalid response from eurmtl.me: hash not found');
        }

        return "https://eurmtl.me/sign_tools/" . $data['hash'];
    }

    private function fetchBalances(string $target): array
    {
        $ReceiverAccount = $this->Stellar->requestAccount($target);
        $balances = [];
        foreach ($ReceiverAccount->getBalances()->toArray() as $Balance) {
            if ($Balance->getAssetType() === Asset::TYPE_NATIVE) {
                $balances['XLM'] = $Balance->getBalance();
            } else {
                $balances[$Balance->getAssetCode() . '-' . $Balance->getAssetIssuer()] = $Balance->getBalance();
            }
        }

        return $balances;
    }
}
