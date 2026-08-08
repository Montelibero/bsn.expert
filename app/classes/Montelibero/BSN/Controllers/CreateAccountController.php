<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CreateAccountTransactionBuilder;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Montelibero\BSN\StellarTomlImageManager;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Asset\AssetResponse;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class CreateAccountController
{
    private const SCALE = 7;

    public function __construct(
        private readonly BSN $BSN,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentContacts $CurrentContacts,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly Translator $Translator,
        private readonly TokensController $TokensController,
        private readonly StellarTomlImageManager $TomlImageManager,
        private readonly StellarAccountReserveCalculator $ReserveCalculator,
        private readonly CreateAccountTransactionBuilder $TransactionBuilder,
        private readonly Container $Container,
    ) {
    }

    public function CreateAccount(): ?string
    {
        $source_account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$source_account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tools/create_account'),
                302,
            );
            return null;
        }

        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $errors = [];
        $SourceAccount = null;
        $current_account_has_master_key = null;
        $trustline_tokens = [];
        $base_reserve = null;
        $signing_form = null;
        $preview = null;
        $is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $values = $this->requestValues($is_post);
        $selected_token_keys = $is_post ? $this->selectedTokenKeys($_POST['tokens'] ?? null) : [];

        try {
            $SourceAccount = $this->Stellar->requestAccount($source_account_id);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.source_not_found');
        }

        if ($SourceAccount !== null) {
            $current_account_has_master_key = $this->hasActiveMasterKey($SourceAccount);
            try {
                $trustline_tokens = $this->buildTrustlineTokens($SourceAccount);
            } catch (\Throwable) {
                $errors[] = $this->Translator->trans('tools_create_account.errors.tokens_not_loaded');
            }

            try {
                $base_reserve = $this->ReserveCalculator->fetchBaseReserveXlm();
            } catch (\Throwable) {
                $errors[] = $this->Translator->trans('tools_create_account.errors.reserve_not_loaded');
            }
        }

        if ($is_post && $this->scalarString($_POST['action'] ?? '') === 'build') {
            $this->buildTransaction(
                $source_account_id,
                $SourceAccount,
                $trustline_tokens,
                $base_reserve,
                $values,
                $selected_token_keys,
                $errors,
                $preview,
                $signing_form,
            );
        }

        if ($current_account_has_master_key === false) {
            $values['lock_master_key'] = false;
        }

        return $this->Twig->render('tools_create_account.twig', [
            'account' => $this->accountView($source_account_id),
            'source_account_id' => $source_account_id,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'values' => $values,
            'trustline_tokens' => $trustline_tokens,
            'selected_token_keys' => $selected_token_keys,
            'base_reserve' => $base_reserve === null ? null : $this->shortDecimal($base_reserve),
            'current_account_has_master_key' => $current_account_has_master_key,
            'errors' => $errors,
            'preview' => $preview,
            'signing_form' => $signing_form,
        ]);
    }

    /**
     * @param array<string, array> $trustline_tokens
     * @param array{destination:string,starting_balance:string,sponsor:bool,full_ownership:bool,lock_master_key:bool} $values
     * @param list<string> $selected_token_keys
     * @param list<string> $errors
     */
    private function buildTransaction(
        string $source_account_id,
        ?AccountResponse $SourceAccount,
        array $trustline_tokens,
        ?string $base_reserve,
        array $values,
        array $selected_token_keys,
        array &$errors,
        ?array &$preview,
        ?string &$signing_form,
    ): void {
        if ($SourceAccount === null || $base_reserve === null || $errors !== []) {
            return;
        }

        if ($values['lock_master_key'] && !$this->hasActiveMasterKey($SourceAccount)) {
            $errors[] = $this->Translator->trans('tools_create_account.properties.master_key_unavailable');
            return;
        }

        $destination_account_id = $values['destination'];
        if (!$this->isValidAccountId($destination_account_id)) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.destination_invalid');
        } elseif ($destination_account_id === $source_account_id) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.destination_is_source');
        } else {
            try {
                if ($this->Stellar->accountExists($destination_account_id)) {
                    $errors[] = $this->Translator->trans('tools_create_account.errors.destination_exists');
                }
            } catch (\Throwable) {
                $errors[] = $this->Translator->trans('tools_create_account.errors.destination_not_checked');
            }
        }

        $starting_balance = CreateAccountTransactionBuilder::normalizeStartingBalance($values['starting_balance']);
        if ($starting_balance === null) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.starting_balance_invalid');
        }

        $selected_tokens = $this->resolveSelectedTokens($selected_token_keys, $trustline_tokens, $errors);
        $operation_count = CreateAccountTransactionBuilder::operationCount(
            count($selected_tokens),
            $values['sponsor'],
            $values['full_ownership'],
            $values['lock_master_key'],
        );
        if ($operation_count > CreateAccountTransactionBuilder::MAX_OPERATIONS) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.too_many_operations', [
                '%maximum%' => (string) CreateAccountTransactionBuilder::MAX_OPERATIONS,
            ]);
        }

        $new_account_reserve_entries = CreateAccountTransactionBuilder::newAccountReserveEntries(
            count($selected_tokens),
            $values['full_ownership'],
            $values['lock_master_key'],
        );
        $new_account_minimum = $this->ReserveCalculator->requiredReserveForNewEntries(
            $new_account_reserve_entries,
            $base_reserve,
        );
        if (
            !$values['sponsor']
            && $starting_balance !== null
            && bccomp($starting_balance, $new_account_minimum, self::SCALE) < 0
        ) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.starting_balance_low', [
                '%minimum%' => $this->shortDecimal($new_account_minimum),
            ]);
        }

        $source_reserve_entries = CreateAccountTransactionBuilder::currentAccountReserveEntries(
            $values['full_ownership'],
        ) + ($values['sponsor'] ? $new_account_reserve_entries : 0);
        $source_reserve = $this->ReserveCalculator->requiredReserveForNewEntries(
            $source_reserve_entries,
            $base_reserve,
        );
        $fee = CreateAccountTransactionBuilder::maxFeeXlm($operation_count);
        $available = $this->ReserveCalculator->calculateAvailableXlm($SourceAccount, $base_reserve);
        $required_from_source = bcadd(
            $starting_balance ?? '0.0000000',
            bcadd($source_reserve, $fee, self::SCALE),
            self::SCALE,
        );
        if (bccomp($required_from_source, $available, self::SCALE) > 0) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.source_funds_low', [
                '%required%' => $this->shortDecimal($required_from_source),
                '%available%' => $this->shortDecimal($available),
            ]);
        }

        if ($errors !== [] || $starting_balance === null) {
            return;
        }

        $ownership_full_data_key = $values['full_ownership']
            ? $this->nextOwnershipFullDataKey($SourceAccount)
            : null;
        try {
            $xdr = $this->TransactionBuilder->build(
                $SourceAccount,
                $destination_account_id,
                $starting_balance,
                array_column($selected_tokens, 'asset'),
                $values['sponsor'],
                $ownership_full_data_key,
                $values['lock_master_key'],
            );
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_create_account.errors.transaction_failed');
            return;
        }

        $preview = $this->buildPreview(
            $source_account_id,
            $destination_account_id,
            $starting_balance,
            $selected_tokens,
            $values,
            $ownership_full_data_key,
            $base_reserve,
            $new_account_reserve_entries,
            $new_account_minimum,
            $source_reserve_entries,
            $source_reserve,
            $fee,
            $required_from_source,
            $available,
        );
        $signing_form = $this->Container->get(SignController::class)->SignTransaction(
            $xdr,
            null,
            $this->Translator->trans('tools_create_account.signing.description', [
                '%account%' => $source_account_id,
                '%destination%' => $destination_account_id,
            ]),
            $this->Translator->trans('tools_create_account.signing.title'),
        );
    }

    /**
     * @return array{destination:string,starting_balance:string,sponsor:bool,full_ownership:bool,lock_master_key:bool}
     */
    private function requestValues(bool $is_post): array
    {
        $source = $is_post ? $_POST : $_GET;

        return [
            'destination' => strtoupper(trim($this->scalarString($source['destination'] ?? ''))),
            'starting_balance' => trim($this->scalarString($source['starting_balance'] ?? '1')),
            'sponsor' => $this->scalarString($source['sponsor'] ?? '') === '1',
            'full_ownership' => $this->scalarString($source['full_ownership'] ?? '') === '1',
            'lock_master_key' => $this->scalarString($source['lock_master_key'] ?? '') === '1',
        ];
    }

    /** @return list<string> */
    private function selectedTokenKeys(mixed $posted_tokens): array
    {
        if (!is_array($posted_tokens)) {
            return [];
        }

        $selected = [];
        foreach ($posted_tokens as $key => $value) {
            if (!is_string($key) || $this->scalarString($value) !== '1') {
                continue;
            }
            $selected[$key] = true;
        }

        return array_keys($selected);
    }

    /**
     * @param list<string> $selected_token_keys
     * @param array<string, array> $trustline_tokens
     * @param list<string> $errors
     * @return list<array>
     */
    private function resolveSelectedTokens(array $selected_token_keys, array $trustline_tokens, array &$errors): array
    {
        $selected = [];
        foreach ($selected_token_keys as $key) {
            $token = $trustline_tokens[$key] ?? null;
            if ($token === null || !($token['asset'] ?? null) instanceof AssetTypeCreditAlphanum) {
                $errors[] = $this->Translator->trans('tools_create_account.errors.token_invalid', [
                    '%asset%' => $key,
                ]);
                continue;
            }
            $selected[] = $token;
        }

        return $selected;
    }

    private function isValidAccountId(string $account_id): bool
    {
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            return false;
        }

        try {
            KeyPair::fromAccountId($account_id);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function hasActiveMasterKey(AccountResponse $Account): bool
    {
        foreach ($Account->getSigners() as $Signer) {
            if (
                $Signer->getType() === 'ed25519_public_key'
                && $Signer->getKey() === $Account->getAccountId()
            ) {
                return $Signer->getWeight() > 0;
            }
        }

        return false;
    }

    private function nextOwnershipFullDataKey(AccountResponse $Account): string
    {
        $occupied = [];
        foreach ($Account->getData()->getData() as $key => $_) {
            if (preg_match('/\AOwnershipFull([1-9]\d*)\z/D', (string) $key, $match) !== 1) {
                continue;
            }
            $occupied[(int) $match[1]] = true;
        }

        for ($suffix = 1; ; $suffix++) {
            if (!isset($occupied[$suffix])) {
                return 'OwnershipFull' . $suffix;
            }
        }
    }

    /**
     * @param list<array> $selected_tokens
     * @param array{destination:string,starting_balance:string,sponsor:bool,full_ownership:bool,lock_master_key:bool} $values
     * @return array<string, mixed>
     */
    private function buildPreview(
        string $source_account_id,
        string $destination_account_id,
        string $starting_balance,
        array $selected_tokens,
        array $values,
        ?string $ownership_full_data_key,
        string $base_reserve,
        int $new_account_reserve_entries,
        string $new_account_minimum,
        int $source_reserve_entries,
        string $source_reserve,
        string $fee,
        string $required_from_source,
        string $available,
    ): array {
        $source_account = $this->accountView($source_account_id);
        $destination_account = $this->accountView($destination_account_id);
        $operations = [];

        if ($values['sponsor']) {
            $operations[] = $this->previewOperation(
                'transactions.operations.types.begin_sponsoring_future_reserves',
                'operations/begin_sponsoring.twig',
                $source_account,
                ['sponsored' => $destination_account],
            );
        }

        $operations[] = $this->previewOperation(
            'transactions.operations.types.create_account',
            'operations/create_account.twig',
            $source_account,
            [
                'account' => $destination_account,
                'starting_balance' => $starting_balance,
            ],
        );

        foreach ($selected_tokens as $token) {
            $operations[] = $this->previewOperation(
                'transactions.operations.change_trust.actions.open',
                'operations/change_trust.twig',
                $destination_account,
                [
                    'asset' => $token,
                    'limit' => null,
                ],
            );
        }

        if ($ownership_full_data_key !== null) {
            $operations[] = $this->previewOperation(
                'transactions.operations.types.manage_data',
                'operations/manage_data.twig',
                $destination_account,
                $this->manageDataPreview('Owner', $source_account_id),
            );
        }

        if ($values['lock_master_key'] && $values['sponsor']) {
            $operations[] = $this->previewOperation(
                'transactions.operations.types.set_options',
                'operations/set_options.twig',
                $destination_account,
                $this->setOptionsPreview(
                    [
                        'account' => $source_account,
                        'key' => $source_account_id,
                        'weight' => 1,
                    ],
                    'add',
                ),
            );
        }

        if ($values['sponsor']) {
            $operations[] = $this->previewOperation(
                'transactions.operations.types.end_sponsoring_future_reserves',
                'operations/end_sponsoring.twig',
                $destination_account,
                ['begin_sponsor' => $source_account],
            );
        }

        if ($ownership_full_data_key !== null) {
            $operations[] = $this->previewOperation(
                'transactions.operations.types.manage_data',
                'operations/manage_data.twig',
                $source_account,
                $this->manageDataPreview($ownership_full_data_key, $destination_account_id),
            );
        }

        if ($values['lock_master_key']) {
            $operations[] = $this->previewOperation(
                'transactions.operations.types.set_options',
                'operations/set_options.twig',
                $destination_account,
                $this->setOptionsPreview(
                    $values['sponsor'] ? null : [
                        'account' => $source_account,
                        'key' => $source_account_id,
                        'weight' => 1,
                    ],
                    $values['sponsor'] ? null : 'add',
                    0,
                    ['low' => 1, 'med' => 1, 'high' => 1],
                ),
            );
        }

        return [
            'destination' => $destination_account,
            'starting_balance' => $this->shortDecimal($starting_balance),
            'base_reserve' => $this->shortDecimal($base_reserve),
            'new_account_reserve_entries' => $new_account_reserve_entries,
            'new_account_minimum' => $this->shortDecimal($new_account_minimum),
            'source_reserve_entries' => $source_reserve_entries,
            'source_reserve' => $this->shortDecimal($source_reserve),
            'fee' => $this->shortDecimal($fee),
            'required_from_source' => $this->shortDecimal($required_from_source),
            'available' => $this->shortDecimal($available),
            'operation_count' => count($operations),
            'sponsor' => $values['sponsor'],
            'lock_master_key' => $values['lock_master_key'],
            'requires_new_account_signature' => CreateAccountTransactionBuilder::requiresNewAccountSignature(
                count($selected_tokens),
                $values['sponsor'],
                $values['full_ownership'],
                $values['lock_master_key'],
            ),
            'operations' => $operations,
        ];
    }

    private function previewOperation(string $title_key, string $template, array $source, array $data): array
    {
        return [
            'title' => $this->Translator->trans($title_key),
            'template' => $template,
            'source' => $source,
            'data' => $data,
        ];
    }

    private function manageDataPreview(string $name, string $value): array
    {
        return [
            'name' => $name,
            'cleared' => false,
            'decoded_value' => $value,
            'value_raw' => null,
            'bsn_semantics' => [],
        ];
    }

    private function setOptionsPreview(
        ?array $signer,
        ?string $signer_action,
        ?int $master_weight = null,
        array $thresholds = [],
    ): array {
        return [
            'signer' => $signer,
            'signer_action' => $signer_action,
            'master_weight' => $master_weight,
            'thresholds' => $thresholds,
            'home_domain' => null,
            'inflation_destination' => null,
            'flags' => ['set' => [], 'clear' => []],
        ];
    }

    /** @return array<string, array> */
    private function buildTrustlineTokens(AccountResponse $Account): array
    {
        $tokens = [];
        foreach ($Account->getBalances() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse || $Balance->getAssetType() === Asset::TYPE_NATIVE) {
                continue;
            }

            $Asset = $this->assetFromBalance($Balance);
            if (!$Asset instanceof AssetTypeCreditAlphanum) {
                continue;
            }

            $token = $this->assetView($Asset);
            $key = $this->assetKey($token);
            $tokens[$key] = $token + [
                'key' => $key,
                'asset' => $Asset,
            ];
        }

        foreach ($this->issuedTokenOptions($Account->getAccountId()) as $key => $token) {
            if (isset($tokens[$key])) {
                continue;
            }
            $tokens[$key] = $token + [
                'key' => $key,
            ];
        }

        uasort($tokens, static function (array $a, array $b): int {
            $a_known = (bool) ($a['is_known'] ?? false);
            $b_known = (bool) ($b['is_known'] ?? false);
            if ($a_known !== $b_known) {
                return $a_known ? -1 : 1;
            }

            return strcasecmp(
                ($a['code'] ?? '') . '-' . ($a['issuer'] ?? ''),
                ($b['code'] ?? '') . '-' . ($b['issuer'] ?? ''),
            );
        });

        return $tokens;
    }

    /** @return array<string, array> */
    private function issuedTokenOptions(string $issuer): array
    {
        $tokens = [];
        $page = $this->Stellar->assets()->forAssetIssuer($issuer)->limit(200)->execute();
        do {
            foreach ($page->getAssets() as $AssetResponse) {
                if (!$AssetResponse instanceof AssetResponse) {
                    continue;
                }

                $issued_amount = $this->issuedAmount($AssetResponse);
                if (bccomp($issued_amount, '0', self::SCALE) <= 0) {
                    continue;
                }

                $code = $AssetResponse->getAssetCode();
                $asset_issuer = $AssetResponse->getAssetIssuer();
                if ($code === null || $asset_issuer === null) {
                    continue;
                }

                $Asset = Asset::createNonNativeAsset($code, $asset_issuer);
                if (!$Asset instanceof AssetTypeCreditAlphanum) {
                    continue;
                }
                $token = $this->assetView($Asset);
                $key = $this->assetKey($token);
                $tokens[$key] = $token + [
                    'key' => $key,
                    'asset' => $Asset,
                ];
            }
            $page = $page->getNextPage();
        } while ($page !== null && $page->getAssets()->count() > 0);

        return $tokens;
    }

    private function issuedAmount(AssetResponse $AssetResponse): string
    {
        $Balances = $AssetResponse->getBalances();
        $amount = '0.0000000';
        foreach ([
            $Balances->getAuthorized(),
            $Balances->getAuthorizedToMaintainLiabilities(),
            $Balances->getUnauthorized(),
            $AssetResponse->getClaimableBalancesAmount(),
            $AssetResponse->getLiquidityPoolsAmount(),
            $AssetResponse->getContractsAmount() ?? '0',
            $AssetResponse->getArchivedContractsAmount() ?? '0',
        ] as $part) {
            $amount = bcadd($amount, $part, self::SCALE);
        }

        return $amount;
    }

    private function assetFromBalance(AccountBalanceResponse $Balance): ?Asset
    {
        $code = $Balance->getAssetCode();
        $issuer = $Balance->getAssetIssuer();
        if ($code === null || $issuer === null) {
            return null;
        }

        return Asset::createNonNativeAsset($code, $issuer);
    }

    private function assetView(AssetTypeCreditAlphanum $Asset): array
    {
        $issuer = $Asset->getIssuer();
        $code = $Asset->getCode();
        $known_token = $this->TokensController->getKnownTokenByCode($code);
        $token = [
            'code' => $code,
            'issuer' => $issuer,
            'url' => '/tokens/' . rawurlencode($code . '-' . $issuer),
            'is_known' => $known_token !== null && $known_token['issuer'] === $issuer,
        ];
        if ($token['is_known']) {
            $token['url'] = '/tokens/' . rawurlencode($code);
        }

        $this->TomlImageManager->applyTokenImage($token);

        return $token;
    }

    private function assetKey(array $asset): string
    {
        return (string) $asset['code'] . '-' . (string) $asset['issuer'];
    }

    private function accountView(string $account_id): array
    {
        return $this->CurrentContacts->serialize($this->BSN->makeAccountById($account_id));
    }

    private function shortDecimal(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');
        return $value === '' || $value === '-0' ? '0' : $value;
    }

    private function scalarString(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
