<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\ClawbackOperationBuilder;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Twig\Environment;

class MtlaRpExclusionController
{
    private const MTLA_ACCOUNT = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';
    private const MAX_OPERATIONS = 100;

    private Environment $Twig;
    private StellarSDK $Stellar;
    private BSN $BSN;
    private SignController $SignController;
    private ?array $gristTimeTokensByAccount = null;

    public function __construct(Environment $Twig, StellarSDK $Stellar, BSN $BSN, SignController $SignController)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
        $this->BSN = $BSN;
        $this->SignController = $SignController;
    }

    public function MtlaRpExclusion(): string
    {
        $csrf_token = md5(session_id() . 'mtla_rp_exclusion');

        $error = '';
        $warning = '';
        $transaction = '';
        $signing_form = null;
        $items = [];
        $operations_count = 0;

        $memo = $_POST['memo'] ?? 'RP exclusion';
        $seq_num = $_POST['seq_num'] ?? '';

        if (
            ($_POST ?? [])
            && ($_POST['csrf_token'] ?? null) === $csrf_token
            && ($_POST['accounts'] ?? '')
        ) {
            $items = $this->parseAccounts($_POST['accounts'], $error, $warning);

            if (strlen($memo) > 28) {
                $error .= "Длина мемо не может быть больше 28 байт\n";
            }
            if ($seq_num !== '' && !preg_match('/^\d+$/', $seq_num)) {
                $error .= "Seq num должен быть числом\n";
            }

            if (!$error) {
                try {
                    [$transaction, $items, $operations_count] = $this->buildTransaction($items, $memo, $seq_num);
                    if ($transaction) {
                        $signing_form = $this->SignController->SignTransaction($transaction, null, $memo ?: 'RP exclusion');
                    }
                } catch (\Throwable $Throwable) {
                    $error .= $Throwable->getMessage() . "\n";
                }
            }
        }

        return $this->Twig->render('tools_mtla_rp_exclusion.twig', [
            'csrf_token' => $csrf_token,
            'accounts' => $_POST['accounts'] ?? '',
            'memo' => $memo,
            'seq_num' => $seq_num,
            'error' => trim($error),
            'warning' => trim($warning),
            'transaction' => $transaction,
            'signing_form' => $signing_form,
            'items' => $items,
            'operations_count' => $operations_count,
        ]);
    }

    private function parseAccounts(string $input, string &$error, string &$warning): array
    {
        $items = [];
        $lines = preg_split('/\r\n|\r|\n/', $input);
        $lines = array_filter(array_map('trim', $lines));

        foreach ($lines as $line) {
            if (!preg_match('/^(?<account>G[A-Z2-7]{55})(?:\s+(?<levels>[12]))?$/i', $line, $match)) {
                $error .= 'Неверный формат строки: ' . $line . "\n";
                continue;
            }

            $account_id = strtoupper($match['account']);
            $levels = isset($match['levels']) && $match['levels'] !== '' ? (int) $match['levels'] : 1;

            if (isset($items[$account_id])) {
                $error .= 'Аккаунт указан несколько раз: ' . $account_id . "\n";
                continue;
            }

            $Account = $this->BSN->getAccountById($account_id);
            if (!$Account instanceof Account) {
                $error .= 'Аккаунт не найден в слепке BSN: ' . $account_id . "\n";
                continue;
            }

            if ($Account->getBalance('MTLAP') < 4) {
                $error .= 'У аккаунта ' . $account_id . ' меньше 4 MTLAP по данным BSN' . "\n";
                continue;
            }

            try {
                $tt_asset = $this->resolveTimeTokenAsset($Account);
            } catch (\RuntimeException $RuntimeException) {
                $warning .= $RuntimeException->getMessage()
                    . '. TT-шаги для этого аккаунта будут пропущены.' . "\n";
                $tt_asset = null;
            }

            $items[$account_id] = [
                'account' => $account_id,
                'levels' => $levels,
                'tt_code' => $tt_asset['code'] ?? null,
                'tt_issuer' => $tt_asset['issuer'] ?? null,
                'tt_asset_key' => $tt_asset['key'] ?? null,
                'bsn_mtlap_balance' => $Account->getBalance('MTLAP'),
            ];
        }

        if (!$items) {
            $error .= "Нет аккаунтов для обработки\n";
        }

        return array_values($items);
    }

    private function resolveTimeTokenAsset(Account $Account): array
    {
        $code = trim((string) $Account->getProfileSingleItem('TimeTokenCode'));
        $issuer = $Account->getId();
        if (BSN::validateTokenNameFormat($code)) {
            $TagTimeTokenIssuer = $this->BSN->findTagByName('TimeTokenIssuer') ?? $this->BSN->makeTagByName('TimeTokenIssuer');
            if ($tt_issuers = $Account->getOutcomeLinks($TagTimeTokenIssuer)) {
                $issuer = $tt_issuers[0]->getId();
            } elseif ($tt_issuer_profile = trim((string) $Account->getProfileSingleItem('TimeTokenIssuer'))) {
                $issuer = $tt_issuer_profile;
            }

            if (BSN::validateStellarAccountIdFormat($issuer)) {
                return [
                    'code' => $code,
                    'issuer' => $issuer,
                    'key' => $code . '-' . $issuer,
                ];
            }
        }

        $grist_asset = $this->fetchTimeTokenFromGrist($Account->getId());
        if ($grist_asset !== null) {
            return $grist_asset;
        }

        throw new \RuntimeException('Не найден корректный TT для ' . $Account->getId() . ' ни в BSN, ни в grist');
    }

    private function fetchTimeTokenFromGrist(string $account_id): ?array
    {
        if ($this->gristTimeTokensByAccount === null) {
            $this->gristTimeTokensByAccount = [];
            $grist_response = \gristRequest(
                'https://montelibero.getgrist.com/api/docs/aYk6cpKAp9CDPJe51sP3AT/tables/Users/records',
                'GET'
            );

            foreach ($grist_response['records'] ?? [] as $item) {
                $fields = $item['fields'] ?? [];
                $stellar = trim((string) ($fields['Stellar'] ?? ''));
                $code = trim((string) ($fields['Own_token'] ?? ''));
                $issuer = trim((string) (($fields['Alt_issuer'] ?? '') ?: $stellar));

                if (
                    !BSN::validateStellarAccountIdFormat($stellar)
                    || !BSN::validateTokenNameFormat($code)
                    || !BSN::validateStellarAccountIdFormat($issuer)
                ) {
                    continue;
                }

                $this->gristTimeTokensByAccount[$stellar] = [
                    'code' => $code,
                    'issuer' => $issuer,
                    'key' => $code . '-' . $issuer,
                ];
            }
        }

        return $this->gristTimeTokensByAccount[$account_id] ?? null;
    }

    private function buildTransaction(array $items, string $memo, string $seq_num): array
    {
        $MtlaAccount = $this->Stellar->requestAccount(self::MTLA_ACCOUNT);
        $mtla_balances = $this->indexBalances($MtlaAccount);
        $mtla_data = $MtlaAccount->getData()->getData();
        $mtlap_asset = Asset::createNonNativeAsset('MTLAP', self::MTLA_ACCOUNT);

        /** @var AbstractOperation[] $operations */
        $operations = [];
        $processed_tt_assets = [];

        foreach ($items as $index => $item) {
            $tt_asset_key = $item['tt_asset_key'];

            $tt_balance_on_mtla = $tt_asset_key !== null
                ? ($mtla_balances[$tt_asset_key]['balance'] ?? null)
                : null;
            $items[$index]['mtla_tt_balance'] = $tt_balance_on_mtla;
            $items[$index]['had_tt'] = $tt_asset_key !== null;
            $items[$index]['had_mtla_tt_trustline'] = $tt_balance_on_mtla !== null;
            $items[$index]['tt_operations_added'] = false;

            if ($tt_asset_key !== null && !isset($processed_tt_assets[$tt_asset_key])) {
                $tt_asset = Asset::createNonNativeAsset($item['tt_code'], $item['tt_issuer']);
                $processed_tt_assets[$tt_asset_key] = true;
                if ($tt_balance_on_mtla !== null && (float) $tt_balance_on_mtla > 0) {
                    $Operation = new PaymentOperationBuilder($item['tt_issuer'], $tt_asset, $tt_balance_on_mtla);
                    $Operation->setSourceAccount(self::MTLA_ACCOUNT);
                    $operations[] = $Operation->build();
                }
                if ($tt_balance_on_mtla !== null) {
                    $Operation = new ChangeTrustOperationBuilder($tt_asset, '0');
                    $Operation->setSourceAccount(self::MTLA_ACCOUNT);
                    $operations[] = $Operation->build();
                }
                $items[$index]['tt_operations_added'] = $tt_balance_on_mtla !== null;
            }

            $b_tag_keys = $this->findMtlaTagKeysForAccount($mtla_data, 'B', $item['account']);
            $items[$index]['b_tag_keys'] = $b_tag_keys;

            foreach ($b_tag_keys as $key_name) {
                $Operation = new ManageDataOperationBuilder($key_name, null);
                $Operation->setSourceAccount(self::MTLA_ACCOUNT);
                $operations[] = $Operation->build();
            }

            $clawback_amount = number_format($item['levels'], 7, '.', '');
            $items[$index]['clawback_amount'] = $clawback_amount;

            $Operation = new ClawbackOperationBuilder(
                $mtlap_asset,
                MuxedAccount::fromAccountId($item['account']),
                $clawback_amount
            );
            $Operation->setSourceAccount(self::MTLA_ACCOUNT);
            $operations[] = $Operation->build();
        }

        if (count($operations) > self::MAX_OPERATIONS) {
            throw new \RuntimeException(
                'Слишком много операций для одной транзакции: ' . count($operations) . ' (лимит ' . self::MAX_OPERATIONS . ')'
            );
        }

        $Transaction = new Transaction(
            $MtlaAccount->getMuxedAccount(),
            $seq_num ? new BigInteger($seq_num) : $MtlaAccount->getIncrementedSequenceNumber(),
            $operations,
            $memo ? Memo::text($memo) : Memo::none(),
            null,
            count($operations) * 10000,
        );

        return [
            $Transaction->toEnvelopeXdrBase64(),
            $items,
            count($operations),
        ];
    }

    private function indexBalances(AccountResponse $Account): array
    {
        $balances = [];
        foreach ($Account->getBalances()->toArray() as $Balance) {
            if (
                !($Balance instanceof AccountBalanceResponse)
                || $Balance->getAssetType() === Asset::TYPE_NATIVE
                || !$Balance->getAssetCode()
                || !$Balance->getAssetIssuer()
            ) {
                continue;
            }

            $balances[$Balance->getAssetCode() . '-' . $Balance->getAssetIssuer()] = [
                'balance' => (string) $Balance->getBalance(),
            ];
        }

        return $balances;
    }

    private function findMtlaTagKeysForAccount(array $data, string $tag_name, string $account_id): array
    {
        $keys = [];

        foreach ($data as $key_name => $value) {
            $decoded_value = base64_decode($value, true);
            if ($decoded_value !== $account_id) {
                continue;
            }

            if (!preg_match(
                '/^\s*(?<tag>[a-z0-9_]+?)\s*(:\s*(?<extra>[a-z0-9_]+?))?\s*(?<suffix>\d*)\s*$/i',
                $key_name,
                $match
            )) {
                continue;
            }

            $full_tag_name = $match['tag'] . ($match['extra'] ? ':' . $match['extra'] : '');
            if (strcasecmp($full_tag_name, $tag_name) !== 0) {
                continue;
            }

            $keys[] = $key_name;
        }

        return $keys;
    }
}
