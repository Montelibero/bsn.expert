<?php

namespace Montelibero\BSN\MTLA\CalcDelegations;

use Closure;
use RuntimeException;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\BeginSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\EndSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Account\AccountSignerResponse;
use Soneso\StellarSDK\SetOptionsOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Xdr\XdrSignerKey;
use Soneso\StellarSDK\Xdr\XdrSignerKeyType;

class CalcVoices
{
    private StellarSDK $Stellar;
    private string $main_account;
    private string $the_token;
    private Closure $logger;
    private bool $debug_mode = false;

    private AccountCollection $Accounts;
    private array $delegates_assembly = [];
    private array $delegates_council = [];
    private array $additional_accounts;

    public function __construct(StellarSDK $Stellar, string $main_account, string $the_token, array $additional_accounts = [])
    {
        $this->Stellar = $Stellar;
        $this->main_account = $main_account;
        $this->the_token = $the_token;
        $this->Accounts = new AccountCollection();
        $this->additional_accounts = $additional_accounts;
    }

    //region Logging
    public function setLogger(Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function setDefaultLogger(): void
    {
        $this->setLogger(function (bool $debug, string $string) {
            if (!$debug || $this->debug_mode) {
                print $string . "\n";
            }
        });
    }

    public function isDebugMode(?bool $debug_mode = null): bool
    {
        if ($debug_mode !== null) {
            $this->debug_mode = $debug_mode;
        }

        return $this->debug_mode;
    }

    public function log(string $string = ''): void
    {
        ($this->logger)(true, $string);
    }

    public function print(string $string = ''): void
    {
        ($this->logger)(false, $string);
    }

    //endregion

    public function run(): array
    {
        if (!isset($this->logger)) {
            $this->setDefaultLogger();
        }

        $this->loadTokenHolders();

        $this->processCouncilDelegations();
        $result = $this->analiseCouncilDelegations();

        // TODO: return!
        $result['council_candidates'] = $this->getCouncilCandidates($result['roots']);
        //  $this->updateCouncil($council_candidates);

        return $result;
    }

    private function loadTokenHolders(): void
    {
        $time = microtime(true);
        $Accounts = $this->Stellar
            ->accounts()
            ->forAsset(
                Asset::createNonNativeAsset(
                    $this->the_token,
                    $this->main_account
                )
            )
            ->limit(200)
            ->execute();
        $this->log('(1) Loaded accounts in ' . (microtime(true) - $time) . ' seconds.');
        $accounts = [];
        do {
            $this->log('Fetch accounts page.');
            foreach ($Accounts->getAccounts() as $Account) {
                $accounts[] = $Account;
            }
            $time = microtime(true);
            if ($Accounts->getAccounts()->count() === 200) {
                $Accounts = $Accounts->getNextPage();
                $this->log('(2) Loaded accounts in ' . (microtime(true) - $time) . ' seconds.');
            } else {
                $Accounts = null;
            }
        } while ($Accounts && $Accounts->getAccounts()->count() > 0);

        $this->log('Открывшие линии доверия к MTLAP:');

        foreach ($accounts as $AccountResponse) {
            if ($AccountResponse instanceof AccountResponse) {
                if ($AccountResponse->getAccountId() === 'GDGC46H4MQKRW3TZTNCWUU6R2C7IPXGN7HQLZBJTNQO6TW7ZOS6MSECR') {
                    continue;
                }
                if (!$this->getAmountOfTokens($AccountResponse)) {
                    continue;
                }
                $Account = $this->processStellarAccount($AccountResponse);

                $this->log(sprintf(
                    "%s\t%s\t%s\t%s",
                    $Account->getId(),
                    $Account->getTokenAmount(),
                    $this->delegates_assembly[$Account->getId()] ?? '',
                    $this->delegates_council[$Account->getId()] ?? ''
                ));
            }
        }

        $this->log();
    }

    private function getAmountOfTokens(AccountResponse $Account): int
    {
        foreach ($Account->getBalances()->toArray() as $Asset) {
            if (($Asset instanceof AccountBalanceResponse)
                && $Asset->getAssetCode() === $this->the_token
                && $Asset->getAssetIssuer() === $this->main_account
            ) {
                return (int)$Asset->getBalance();
            }
        }

        return 0;
    }

    private function getAssemblyDelegation(AccountResponse $Account): ?string
    {
        $Data = $Account->getData();
        $delegate = $Data->get('mtla_delegate') ?? $Data->get('mtla_a_delegate');
        if ($this->validateStellarAccountIdFormat($delegate)) {
            return $delegate;
        }

        return null;
    }

    private function getCouncilDelegation(AccountResponse $Account): ?string
    {
        $Data = $Account->getData();
        $delegate = $Data->get('mtla_c_delegate');
        if ($this->validateStellarAccountIdFormat($delegate)) {
            return $delegate;
        }

        return null;
    }

    private function validateStellarAccountIdFormat(?string $account_id): bool
    {
        if (!$account_id) {
            return false;
        }

        return preg_match('/\AG[A-Z2-7]{55}\Z/', $account_id);
    }

    private function processCouncilDelegations(): void
    {
        $this->log("Проверка делегаций для Совета:");

        $found_delegates = array_keys($this->delegates_council);

        $Processed = new AccountCollection();

        while ($delegator_id = array_shift($found_delegates)) {
            $this->log("Начало для " . $delegator_id);
            $Delegator = $this->Accounts->getById($delegator_id);
            if (!$Delegator) {
                throw new RuntimeException('Unknown account_id (not from the preloaded list: ' . $delegator_id . ').');
            }

            $Chain = new AccountCollection();
            $Chain->addItem($Delegator);

            do {
                $target_id = $this->delegates_council[$Delegator->getId()];
                $this->log("Делегирует " . $target_id);
                $Target = $this->Accounts->getById($target_id);
                if (!$Target) {
                    $this->log("Подгружаем новый аккаунт " . $target_id);
                    if (!($Target = $this->loadNewAccount($target_id))) {
                        $this->log("Подгрузка неудачна");
                        continue 2;
                    }
                }

                $Delegator->setDelegateCouncilTo($Target);
                $Processed->addItem($Delegator);

                if ($Chain->isExists($Target)) {
                    $Delegator->isBrokenCouncilDelegate(true);
                    $this->log('ENDLES LOOP!');
                    continue 2;
                }
                $Chain->addItem($Target);

                if (array_key_exists($Target->getId(), $this->delegates_council) && !$Processed->isExists($Target)) {
                    $Delegator = $Target;
                } else {
                    $Delegator = null;
                }
            } while ($Delegator);
        }

        $this->log();
    }

    private function loadNewAccount(string $id): ?Account
    {
        try {
            $time = microtime(true);
            $AccountResponse = $this->Stellar->requestAccount($id);
            $this->log('Loaded a single account in ' . (microtime(true) - $time) . ' seconds.');
            return $this->processStellarAccount($AccountResponse);
        } catch (HorizonRequestException) {
            return null;
        }
    }

    private function processStellarAccount(AccountResponse $AccountResponse): Account
    {
        $account_id = $AccountResponse->getAccountId();
        $token_count = $this->getAmountOfTokens($AccountResponse);
        $Account = new Account($account_id, $token_count);
        $ready_to_council = false;
        if (($data_value = $AccountResponse->getData()->get('mtla_c_delegate')) && $data_value === 'ready') {
            $ready_to_council = true;
        }
        $Account->isReadyToCouncil($ready_to_council);
        $this->Accounts->addItem($Account);
        if ($delegate = $this->getAssemblyDelegation($AccountResponse)) {
            $this->delegates_assembly[$account_id] = $delegate;
        }
        if (($delegate = $this->getCouncilDelegation($AccountResponse)) && $delegate !== $account_id) {
            $this->delegates_council[$account_id] = $delegate;
        }

        return $Account;
    }

    private function analiseCouncilDelegations(): array
    {
        $ListBrokenDelegates = new AccountCollection();
        $ListNoDelegates = new AccountCollection();
        foreach ($this->Accounts->asArray() as $Account) {
            if ($Account->isBrokenCouncilDelegate()) {
                $ListBrokenDelegates->addItem($Account);
                continue;
            }

            if (!$Account->getDelegateCouncilTo()) {
                $ListNoDelegates->addItem($Account);
            }
        }

        $result = [
            'broken' => [],
            'roots' => [],
        ];
        if ($ListBrokenDelegates->isNonEmpty()) {
            $this->log('Сломанные делегации:');
            foreach ($ListBrokenDelegates->asArray() as $Account) {
                $token_power = $Account->getTokenPower();
                $this->log(sprintf("%s\t%s", $Account->getId(), $token_power));
                $result['broken'][] = $Account->getCouncilTree();
            }
            $this->log();
        }

        if ($ListNoDelegates->isNonEmpty()) {
            $this->log('Без делегации:');
            foreach ($ListNoDelegates->asArray() as $Account) {
                $token_power = $Account->getTokenPower();
                $this->log(sprintf("%s\t%s", $Account->getId(), $token_power));
                $result['roots'][] = $Account->getCouncilTree();
            }
            $this->log();
        }

        return $result;
    }

    private function getCouncilCandidates(array $candidates): array
    {
        $candidates_filtered = [];

        foreach ($candidates as $item) {
            $account_id = $item['id'];
            $token_power = $item['own_token_amount'] + $item['delegated_token_amount'];
            $Account = $this->Accounts->getById($account_id);
            if ($Account->isVerified() && $Account->isReadyToCouncil()) {
                $candidates_filtered[$account_id] = [
                    'id' => $account_id,
                    'token_power' => $token_power,
                    'index' => null,
                ];
            }
        }

        uasort($candidates_filtered, function (array $a, array $b) {
            if ($a['token_power'] > $b['token_power']) {
                return -1;
            }

            if ($a['token_power'] < $b['token_power']) {
                return 1;
            }

            return strcmp($a['id'], $b['id']);
        });

        $index = 0;
        foreach ($candidates_filtered as & $item) {
            $item['index'] = ++$index;
        }

        return $candidates_filtered;
    }

    private function printDelegationTree($items, $level = 0): void
    {
        foreach ($items as $item) {
            $this->print(
                str_repeat("\t", $level)
                . $item['id']
                . "\t"
                . ($item['own_token_amount'] + $item['delegated_token_amount'])
            );
            $this->printDelegationTree($item['delegated'], $level + 1);
        }
    }

    public function buildCouncilUpdateTransaction(array $council_candidates, int $limit = 20): ?array
    {
        if (!isset($this->logger)) {
            $this->setDefaultLogger();
        }

        $council_candidates = array_map(static function (array $candidate): array {
            return [
                'address' => $candidate['id'],
                'token_power' => $candidate['token_power'],
            ];
        }, $council_candidates);

        uasort($council_candidates, static function (array $a, array $b): int {
            if ($a['token_power'] > $b['token_power']) {
                return -1;
            }

            if ($a['token_power'] < $b['token_power']) {
                return 1;
            }

            return strcmp($a['address'], $b['address']);
        });

        $top = array_slice(array_keys($council_candidates), 0, $limit);

        $this->log('Ожидаемый состав Совета');

        $voices_sum = 0;
        $calculated_weights = [];
        foreach ($top as $account_id) {
            $sign_weight = $this->calcCouncilMemberVoiceByTokens($council_candidates[$account_id]['token_power']);
            $this->log(sprintf(
                "%s\t%s\t%s",
                $account_id,
                $council_candidates[$account_id]['token_power'],
                $sign_weight,
            ));
            $calculated_weights[$account_id] = $sign_weight;
            $voices_sum += $sign_weight;
        }

        if (!$calculated_weights) {
            return null;
        }

        $this->log("Всего голосов: " . $voices_sum);
        $for_transaction = (int) floor($voices_sum / 2 + 1);
        $this->log("Нужно для мультиподписи транзы: " . $for_transaction);
        $this->log();

        $operations = [];
        $updated_accounts = [];
        $main_account_changes = [];
        $main_account_current_threshold = null;

        $account_list = array_values(array_unique(array_merge([$this->main_account], $this->additional_accounts)));

        foreach ($account_list as $acc_id) {
            $result = $this->updateSigners($acc_id, $calculated_weights, $for_transaction);
            if ($result['operations']) {
                $updated_accounts[] = $acc_id;
            }
            if ($acc_id === $this->main_account) {
                $main_account_changes = $result['changes'];
                $main_account_current_threshold = $result['current_threshold'];
            }
            foreach ($result['operations'] as $item) {
                $operations[] = $item;
            }
        }

        try {
            $StellarAccount = $this->Stellar->requestAccount($this->main_account);
        } catch (HorizonRequestException) {
            throw new RuntimeException('Main MTLA account is unavailable.');
        }

        if (!$operations) {
            $this->log('Нечего изменять.');
            return null;
        }

        $Transaction = new TransactionBuilder($StellarAccount);
        $Transaction->setMaxOperationFee(10000);
        $Transaction->addMemo(Memo::text('Council Update'));
        $Transaction->addOperations($operations);

        return [
            'xdr' => $Transaction->build()->toEnvelopeXdrBase64(),
            'operations_count' => count($operations),
            'updated_accounts' => $updated_accounts,
            'current_threshold' => $main_account_current_threshold,
            'new_threshold' => $for_transaction,
            'signers_count' => count($calculated_weights),
            'main_account_changes' => $main_account_changes,
        ];
    }

    private function updateSigners(string $account_id, array $must_be, int $threshold): array
    {
        $this->log('Расчет для ' . $account_id);
        $current_signs = [];
        try {
            $StellarAccount = $this->Stellar->requestAccount($account_id);
        } catch (HorizonRequestException) {
            $StellarAccount = null;
        }
        $current_threshold = $StellarAccount?->getThresholds()?->getMedThreshold();
        if ($StellarAccount) {
            /** @var AccountSignerResponse $Signer */
            foreach ($StellarAccount->getSigners() as $Signer) {
                if ($Signer->getType() !== 'ed25519_public_key') {
                    continue;
                }
                if ($Signer->getKey() === $account_id) {
                    continue;
                }
                $current_signs[$Signer->getKey()] = $Signer->getWeight();
            }
        }

        $this->log();
        $this->log('Текущий состав мультиподписи:');
        foreach ($current_signs as $acc_id => $weight) {
            $this->log(sprintf("%s\t%s", $acc_id, $weight));
        }
        $this->log();

        // Смотрим что там сейчас
        $this->log('Разница расчетного и текущего:');
        $changes = [];
        $change_details = [];
        // Удаляемые
        foreach ($current_signs as $address => $weight) {
            if (!array_key_exists($address, $must_be)) {
                $changes[$address] = 0;
            }
        }
        // Добавляемые и изменённые
        foreach ($must_be as $address => $weight) {
            $voice = $must_be[$address];
            if (!array_key_exists($address, $current_signs) || $current_signs[$address] !== $voice) {
                $changes[$address] = $voice;
            }
        }
        foreach ($changes as $address => $weight) {
            $diff = 'ошибка1';
            $old = $current_signs[$address] ?? 0;
            if ($weight === $old) {
                $diff = 'ошибка2';
            } else if ($old === 0) {
                $diff = 'новый';
            } else if ($weight < $old) {
                $diff = '−' . $old - $weight;
            } else if ($weight > $old) {
                $diff = '+' . $weight - $old;
            }
            $this->log(sprintf("\t%s\t%s\t%s", $address, $weight, $diff));
            $change_details[] = [
                'id' => $address,
                'old_weight' => $old,
                'new_weight' => $weight,
                'type' => $this->resolveSignerChangeType($old, $weight),
            ];
        }
        if (!$changes) {
            $this->log("\tНет разницы");
        }

        $this->log();

        $operations = [];

        $apply_threshold_changes = function (SetOptionsOperationBuilder $Operation) use ($StellarAccount, $threshold): bool {
            $has_changes = false;
            if (!$StellarAccount || $StellarAccount->getThresholds()->getHighThreshold() !== $threshold) {
                $Operation->setHighThreshold($threshold);
                $has_changes = true;
            }
            if (!$StellarAccount || $StellarAccount->getThresholds()->getMedThreshold() !== $threshold) {
                $Operation->setMediumThreshold($threshold);
                $has_changes = true;
            }
            if (!$StellarAccount || $this->getMasterKeyWeight($StellarAccount)) {
                $Operation->setMasterKeyWeight(0);
                $has_changes = true;
            }

            return $has_changes;
        };

        if ($changes && $account_id !== $this->main_account) {
            $Operation = new BeginSponsoringFutureReservesOperationBuilder($account_id);
            $operations[] = $Operation->build();
        }

        $last_item = array_key_last($changes);
        foreach ($changes as $address => $voice) {
            $Signer = new XdrSignerKey();
            $Signer->setType(new XdrSignerKeyType(XdrSignerKeyType::ED25519));
            $Signer->setEd25519(KeyPair::fromAccountId($address)->getPublicKey());
            $Operation = new SetOptionsOperationBuilder();
            $Operation->setSigner($Signer, $voice);
            if ($address === $last_item) {
                $apply_threshold_changes($Operation);
            }
            if ($account_id !== $this->main_account) {
                $Operation->setSourceAccount($account_id);
            }
            $operations[] = $Operation->build();
        }

        if (!$changes) {
            $Operation = new SetOptionsOperationBuilder();
            if ($apply_threshold_changes($Operation)) {
                if ($account_id !== $this->main_account) {
                    $Operation->setSourceAccount($account_id);
                }
                $operations[] = $Operation->build();
            }
        }

        if ($changes && $account_id !== $this->main_account) {
            $Operation = new EndSponsoringFutureReservesOperationBuilder();
            $Operation->setSourceAccount($account_id);
            $operations[] = $Operation->build();
        }

        return [
            'operations' => $operations,
            'changes' => $change_details,
            'current_threshold' => $current_threshold,
        ];
    }

    public function calcCouncilMemberVoiceByTokens(int $token_power): mixed
    {
        return (int) floor(log(max($token_power, 2) - 1, 10) + 1);
    }

    private function resolveSignerChangeType(int $old_weight, int $new_weight): string
    {
        if ($old_weight === 0 && $new_weight > 0) {
            return 'added';
        }

        if ($old_weight > 0 && $new_weight === 0) {
            return 'removed';
        }

        if ($new_weight > $old_weight) {
            return 'increased';
        }

        if ($new_weight < $old_weight) {
            return 'decreased';
        }

        return 'changed';
    }

    private function getMasterKeyWeight(AccountResponse $Account): int
    {
        /** @var AccountSignerResponse $Signer */
        foreach ($Account->getSigners()->toArray() as $Signer) {
            if ($Signer->getKey() === $Account->getAccountId()) {
                return $Signer->getWeight();
            }
        }

        throw new RuntimeException('No master key found!');
    }
}
