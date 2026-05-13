<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\ManageSellOfferOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountDataResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Offers\OfferResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class MigrationController
{
    private const TOKEN_IGNORE = 'ignore';
    private const TOKEN_OPEN = 'open';
    private const TOKEN_TRANSFER = 'open_transfer';
    private const TOKEN_CLOSE = 'open_transfer_close';
    private const OFFER_IGNORE = 'ignore';
    private const OFFER_CLOSE = 'close';
    private const OFFER_MOVE = 'move';
    private const DATA_IGNORE = 'ignore';
    private const DATA_COPY = 'copy';
    private const DATA_MOVE = 'move';
    private const MAX_OPERATIONS_PER_TRANSACTION = 100;
    private array $issuer_auth_required_cache = [];

    public function __construct(
        private readonly BSN $BSN,
        private readonly CurrentUser $CurrentUser,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly Translator $Translator,
        private readonly Container $Container,
        private readonly StellarAccountReserveCalculator $ReserveCalculator,
    ) {
    }

    public function Migration(): string
    {
        $raw_get_source = strtoupper(trim((string) ($_GET['source'] ?? '')));
        $raw_get_target = strtoupper(trim((string) ($_GET['target'] ?? '')));
        $source = strtoupper(trim((string) ($_POST['source'] ?? $_GET['source'] ?? '')));
        if ($source === '') {
            $source = $this->CurrentUser->getCurrentAccountId() ?? '';
        }
        $target = strtoupper(trim((string) ($_POST['target'] ?? $_GET['target'] ?? '')));
        $fee_payer = (string) ($_POST['actor'] ?? $_GET['actor'] ?? $_POST['fee_payer'] ?? $_GET['fee_payer'] ?? 'source');
        if (!in_array($fee_payer, ['source', 'target'], true)) {
            $fee_payer = 'source';
        }

        $errors = [];
        $analysis = null;
        $signing_forms = [];
        $xdr_warnings = [];
        $show_form = (string) ($_GET['show_form'] ?? '') === 'yes';
        $action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
        if (
            $action === ''
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && BSN::validateStellarAccountIdFormat($raw_get_source)
            && BSN::validateStellarAccountIdFormat($raw_get_target)
        ) {
            $action = 'analyze';
        }

        if (in_array($action, ['analyze', 'build'], true)) {
            $analysis = $this->analyze($source, $target, $fee_payer, $_POST, $errors);
        }

        if (
            $analysis !== null
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && $action === 'build'
        ) {
            $this->validatePlan($analysis, $errors);
            if (!$errors) {
                [$signing_forms, $xdr_warnings] = $this->buildSigningForms($analysis);
            }
        }

        return $this->Twig->render('tools_migration.twig', [
            'source' => $source,
            'target' => $target,
            'fee_payer' => $fee_payer,
            'analysis' => $analysis,
            'errors' => $errors,
            'signing_forms' => $signing_forms,
            'xdr_warnings' => $xdr_warnings,
            'xlm_base' => $this->baseReserveHint(),
            'show_account_form' => $analysis === null || $show_form,
        ]);
    }

    private function baseReserveHint(): string
    {
        try {
            $base_reserve = $this->ReserveCalculator->fetchBaseReserveXlm();
        } catch (\Throwable) {
            $base_reserve = '0.5000000';
        }

        return rtrim(rtrim($base_reserve, '0'), '.');
    }

    private function analyze(string $source, string $target, string $fee_payer, array $input, array &$errors): ?array
    {
        if (!BSN::validateStellarAccountIdFormat($source)) {
            $errors[] = $this->Translator->trans('tools_migration.errors.invalid_source');
        }
        if (!BSN::validateStellarAccountIdFormat($target)) {
            $errors[] = $this->Translator->trans('tools_migration.errors.invalid_target');
        }
        if ($source !== '' && $source === $target) {
            $errors[] = $this->Translator->trans('tools_migration.errors.same_accounts');
        }
        if ($errors) {
            return null;
        }

        try {
            $SourceAccount = $this->Stellar->requestAccount($source);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_migration.errors.source_not_found');
            return null;
        }

        try {
            $TargetAccount = $this->Stellar->requestAccount($target);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_migration.errors.target_not_found');
            return null;
        }

        $offers = $this->loadOffers($source);
        $tokens = $this->buildTokenRows($SourceAccount, $TargetAccount, $offers);
        $selected_token_actions = $this->selectedTokenActions($tokens, $input);
        $offer_rows = $this->buildOfferRows($offers, $source);
        $selected_offer_actions = $this->selectedOfferActions($offer_rows, $input);
        $source_data = $this->dataRows($SourceAccount->getData());
        $target_data_count = count($TargetAccount->getData()->getKeys());
        $selected_data_action = $this->selectedDataAction($input, $source_data, $target_data_count);
        $reserve = $this->analyzeTargetReserve(
            $TargetAccount,
            $tokens,
            $selected_token_actions,
            $offer_rows,
            $selected_offer_actions,
            $source_data,
            $selected_data_action,
        );

        return [
            'source_account' => $this->BSN->makeAccountById($source)->jsonSerialize(),
            'target_account' => $this->BSN->makeAccountById($target)->jsonSerialize(),
            'source_account_response' => $SourceAccount,
            'target_account_response' => $TargetAccount,
            'source' => $source,
            'target' => $target,
            'fee_payer' => $fee_payer,
            'tokens' => $tokens,
            'offers' => $offer_rows,
            'source_data' => $source_data,
            'target_data_count' => $target_data_count,
            'selected_token_actions' => $selected_token_actions,
            'selected_offer_actions' => $selected_offer_actions,
            'selected_data_action' => $selected_data_action,
            'reserve' => $reserve,
        ];
    }

    /**
     * @return list<OfferResponse>
     */
    private function loadOffers(string $account_id): array
    {
        $result = [];
        $page = $this->Stellar->offers()->forAccount($account_id)->limit(200)->execute();
        do {
            foreach ($page->getOffers() as $Offer) {
                $result[] = $Offer;
            }
            $page = $page->getNextPage();
        } while ($page !== null && $page->getOffers()->count() > 0);

        return $result;
    }

    /**
     * @param list<OfferResponse> $offers
     */
    private function buildTokenRows(AccountResponse $SourceAccount, AccountResponse $TargetAccount, array $offers): array
    {
        $target_balances = [];
        foreach ($TargetAccount->getBalances() as $TargetBalance) {
            $key = $this->balanceAssetKey($TargetBalance);
            if ($key !== null) {
                $target_balances[$key] = $TargetBalance;
            }
        }

        $offer_asset_roles = [];
        foreach ($offers as $Offer) {
            $selling_key = $this->assetKey($Offer->getSelling());
            $buying_key = $this->assetKey($Offer->getBuying());
            if ($selling_key !== null) {
                $offer_asset_roles[$selling_key]['selling'] = true;
            }
            if ($buying_key !== null) {
                $offer_asset_roles[$buying_key]['buying'] = true;
            }
        }

        $tokens = [];
        foreach ($SourceAccount->getBalances() as $Balance) {
            $key = $this->balanceAssetKey($Balance);
            if ($key === null) {
                continue;
            }

            $TargetBalance = $target_balances[$key] ?? null;
            $issuer = $Balance->getAssetIssuer();
            $source_authorized = $Balance->getIsAuthorized() !== false || bccomp($Balance->getBalance(), '0', 7) <= 0;
            $can_transfer = $source_authorized;
            $has_target_trustline = $TargetBalance !== null;
            $available_actions = [self::TOKEN_IGNORE, self::TOKEN_OPEN];
            if ($can_transfer) {
                $available_actions[] = self::TOKEN_TRANSFER;
                $available_actions[] = self::TOKEN_CLOSE;
            }

            $tokens[$key] = [
                'key' => $key,
                'code' => $Balance->getAssetCode(),
                'issuer' => $issuer,
                'url' => '/tokens/' . rawurlencode($Balance->getAssetCode() . '-' . $issuer),
                'balance' => $Balance->getBalance(),
                'target_has_trustline' => $has_target_trustline,
                'source_authorized' => $source_authorized,
                'can_transfer' => $can_transfer,
                'available_actions' => $available_actions,
                'source_balance_response' => $Balance,
                'target_balance_response' => $TargetBalance,
                'offer_roles' => $offer_asset_roles[$key] ?? [],
            ];
        }

        uasort($tokens, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $tokens;
    }

    /**
     * @param list<OfferResponse> $offers
     */
    private function buildOfferRows(array $offers, string $source): array
    {
        $rows = [];
        foreach ($offers as $Offer) {
            $id = $Offer->getOfferId();
            $available_actions = [self::OFFER_IGNORE, self::OFFER_CLOSE];
            if (!$this->assetIsIssuedBy($Offer->getSelling(), $source)) {
                $available_actions[] = self::OFFER_MOVE;
            }
            $rows[$id] = [
                'id' => $id,
                'selling' => $this->assetView($Offer->getSelling()),
                'buying' => $this->assetView($Offer->getBuying()),
                'selling_key' => $this->assetKey($Offer->getSelling()),
                'buying_key' => $this->assetKey($Offer->getBuying()),
                'amount' => $Offer->getAmount(),
                'price' => $Offer->getPrice(),
                'total' => bcmul($Offer->getAmount(), $Offer->getPrice(), 7),
                'available_actions' => $available_actions,
                'offer_response' => $Offer,
            ];
        }

        return $rows;
    }

    private function selectedTokenActions(array $tokens, array $input): array
    {
        $posted = $input['token_action'] ?? [];
        $result = [];
        foreach ($tokens as $key => $token) {
            $default = self::TOKEN_IGNORE;
            $action = is_array($posted) ? ($posted[$key] ?? $default) : $default;
            if (!in_array($action, [self::TOKEN_IGNORE, self::TOKEN_OPEN, self::TOKEN_TRANSFER, self::TOKEN_CLOSE], true)) {
                $action = $default;
            }
            $result[$key] = $action;
        }

        return $result;
    }

    private function selectedOfferActions(array $offers, array $input): array
    {
        $posted = $input['offer_action'] ?? [];
        $result = [];
        foreach ($offers as $id => $offer) {
            $action = is_array($posted) ? ($posted[$id] ?? self::OFFER_IGNORE) : self::OFFER_IGNORE;
            if (!in_array($action, [self::OFFER_IGNORE, self::OFFER_CLOSE, self::OFFER_MOVE], true)) {
                $action = self::OFFER_IGNORE;
            }
            if (!in_array($action, $offer['available_actions'], true)) {
                $action = self::OFFER_IGNORE;
            }
            $result[$id] = $action;
        }

        return $result;
    }

    private function selectedDataAction(array $input, array $source_data, int $target_data_count): string
    {
        if (!$source_data || $target_data_count > 0) {
            return self::DATA_IGNORE;
        }

        $action = (string) ($input['data_action'] ?? self::DATA_IGNORE);
        if (!in_array($action, [self::DATA_IGNORE, self::DATA_COPY, self::DATA_MOVE], true)) {
            return self::DATA_IGNORE;
        }

        return $action;
    }

    private function validatePlan(array $analysis, array &$errors): void
    {
        foreach ($analysis['tokens'] as $key => $token) {
            $action = $analysis['selected_token_actions'][$key] ?? self::TOKEN_IGNORE;
            if (!in_array($action, $token['available_actions'], true)) {
                $errors[] = $this->Translator->trans('tools_migration.errors.token_action_unavailable', [
                    '%asset%' => $token['code'],
                ]);
            }

            if (
                $this->tokenActionTransfers($action)
                && bccomp($token['balance'], '0', 7) > 0
                && !$token['target_has_trustline']
                && $this->issuerRequiresAuthorization($token)
            ) {
                $errors[] = $this->Translator->trans('tools_migration.errors.token_requires_issuer_approval', [
                    '%asset%' => $token['code'],
                    '%message%' => $this->Translator->trans('tools_migration.tokens.issuer_auth_required'),
                ]);
            }
        }

        foreach ($analysis['offers'] as $offer_id => $offer) {
            $offer_action = $analysis['selected_offer_actions'][$offer_id] ?? self::OFFER_IGNORE;
            if ($offer_action !== self::OFFER_MOVE) {
                continue;
            }

            $selling_key = $offer['selling_key'];
            if ($selling_key !== null) {
                $token_action = $analysis['selected_token_actions'][$selling_key] ?? self::TOKEN_IGNORE;
                if (!$this->tokenActionTransfers($token_action)) {
                    $errors[] = $this->Translator->trans('tools_migration.errors.offer_move_requires_selling_transfer', [
                        '%offer%' => $offer_id,
                        '%asset%' => $offer['selling']['label'],
                    ]);
                }
            }

            $buying_key = $offer['buying_key'];
            if ($buying_key !== null) {
                $token = $analysis['tokens'][$buying_key] ?? null;
                $token_action = $analysis['selected_token_actions'][$buying_key] ?? self::TOKEN_IGNORE;
                if (!$token || (!$token['target_has_trustline'] && !$this->tokenActionOpens($token_action))) {
                    $errors[] = $this->Translator->trans('tools_migration.errors.offer_move_requires_buying_trustline', [
                        '%offer%' => $offer_id,
                        '%asset%' => $offer['buying']['label'],
                    ]);
                }
            }
        }

        foreach ($analysis['tokens'] as $key => $token) {
            $token_action = $analysis['selected_token_actions'][$key] ?? self::TOKEN_IGNORE;
            if (!$this->tokenActionTransfers($token_action) && $token_action !== self::TOKEN_CLOSE) {
                continue;
            }

            foreach ($analysis['offers'] as $offer_id => $offer) {
                $offer_action = $analysis['selected_offer_actions'][$offer_id] ?? self::OFFER_IGNORE;
                if ($offer_action !== self::OFFER_IGNORE) {
                    continue;
                }

                if ($offer['selling_key'] === $key) {
                    $errors[] = $this->Translator->trans('tools_migration.errors.token_transfer_blocked_by_ignored_selling_offer', [
                        '%asset%' => $token['code'],
                        '%offer%' => $offer_id,
                    ]);
                }
                if ($token_action === self::TOKEN_CLOSE && $offer['buying_key'] === $key) {
                    $errors[] = $this->Translator->trans('tools_migration.errors.token_close_blocked_by_ignored_buying_offer', [
                        '%asset%' => $token['code'],
                        '%offer%' => $offer_id,
                    ]);
                }
            }
        }

        if (
            ($analysis['selected_data_action'] ?? self::DATA_IGNORE) !== self::DATA_IGNORE
            && ($analysis['target_data_count'] ?? 0) > 0
        ) {
            $errors[] = $this->Translator->trans('tools_migration.errors.target_data_not_empty');
        }
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function buildSigningForms(array $analysis): array
    {
        $operations = $this->buildOperations($analysis);
        if (!$operations) {
            return [[], [$this->Translator->trans('tools_migration.warnings.no_operations')]];
        }

        $warnings = [];
        if ($this->hasMovedOffers($analysis)) {
            $warnings[] = $this->Translator->trans('tools_migration.warnings.moved_offers_can_break');
        }

        $FeePayer = $analysis['fee_payer'] === 'target'
            ? $analysis['target_account_response']
            : $analysis['source_account_response'];
        $forms = [];
        foreach (array_chunk($operations, self::MAX_OPERATIONS_PER_TRANSACTION) as $index => $chunk) {
            $Transaction = new TransactionBuilder($FeePayer);
            $Transaction->setMaxOperationFee(10000);
            $Transaction->addMemo(Memo::text('Migration'));
            $Transaction->addOperations($chunk);
            $xdr = $Transaction->build()->toEnvelopeXdrBase64();
            $description = $this->Translator->trans('tools_migration.signing.description', [
                '%number%' => (string) ($index + 1),
                '%total%' => (string) ceil(count($operations) / self::MAX_OPERATIONS_PER_TRANSACTION),
                '%source%' => $analysis['source'],
                '%target%' => $analysis['target'],
            ]);
            $forms[] = $this->Container->get(SignController::class)->SignTransaction($xdr, null, $description);
        }

        return [$forms, $warnings];
    }

    /**
     * @return list<AbstractOperation>
     */
    private function buildOperations(array $analysis): array
    {
        $source = $analysis['source'];
        $target = $analysis['target'];
        $operations = [];
        $moved_offers = [];

        foreach ($analysis['offers'] as $offer_id => $offer) {
            $action = $analysis['selected_offer_actions'][$offer_id] ?? self::OFFER_IGNORE;
            if (!in_array($action, [self::OFFER_CLOSE, self::OFFER_MOVE], true)) {
                continue;
            }
            $operations[] = (new ManageSellOfferOperationBuilder(
                $offer['offer_response']->getSelling(),
                $offer['offer_response']->getBuying(),
                '0',
                $offer['price']
            ))->setOfferId((int) $offer_id)->setSourceAccount($source)->build();
            if ($action === self::OFFER_MOVE) {
                $moved_offers[] = $offer;
            }
        }

        foreach ($analysis['tokens'] as $key => $token) {
            $action = $analysis['selected_token_actions'][$key] ?? self::TOKEN_IGNORE;
            if (!$this->tokenActionOpens($action) || $token['target_has_trustline']) {
                continue;
            }
            $operations[] = (new ChangeTrustOperationBuilder($this->assetFromToken($token)))
                ->setSourceAccount($target)
                ->build();
        }

        foreach ($analysis['tokens'] as $key => $token) {
            $action = $analysis['selected_token_actions'][$key] ?? self::TOKEN_IGNORE;
            if (!$this->tokenActionTransfers($action) || bccomp($token['balance'], '0', 7) <= 0) {
                continue;
            }
            $operations[] = (new PaymentOperationBuilder($target, $this->assetFromToken($token), $token['balance']))
                ->setSourceAccount($source)
                ->build();
        }

        foreach ($analysis['tokens'] as $key => $token) {
            $action = $analysis['selected_token_actions'][$key] ?? self::TOKEN_IGNORE;
            if ($action !== self::TOKEN_CLOSE) {
                continue;
            }
            $operations[] = (new ChangeTrustOperationBuilder($this->assetFromToken($token), '0'))
                ->setSourceAccount($source)
                ->build();
        }

        foreach ($moved_offers as $offer) {
            $operations[] = (new ManageSellOfferOperationBuilder(
                $offer['offer_response']->getSelling(),
                $offer['offer_response']->getBuying(),
                $offer['amount'],
                $offer['price']
            ))->setSourceAccount($target)->build();
        }

        if ($analysis['source_data'] && $analysis['selected_data_action'] !== self::DATA_IGNORE) {
            foreach ($analysis['source_data'] as $item) {
                $operations[] = (new ManageDataOperationBuilder($item['key'], $item['value']))
                    ->setSourceAccount($target)
                    ->build();
            }
            if ($analysis['selected_data_action'] === self::DATA_MOVE) {
                foreach ($analysis['source_data'] as $item) {
                    $operations[] = (new ManageDataOperationBuilder($item['key'], null))
                        ->setSourceAccount($source)
                        ->build();
                }
            }
        }

        return $operations;
    }

    private function analyzeTargetReserve(
        AccountResponse $TargetAccount,
        array $tokens,
        array $selected_token_actions,
        array $offers,
        array $selected_offer_actions,
        array $source_data,
        string $selected_data_action,
    ): array {
        $new_entries = 0;
        foreach ($tokens as $key => $token) {
            if (
                !$token['target_has_trustline']
                && $this->tokenActionOpens($selected_token_actions[$key] ?? self::TOKEN_IGNORE)
            ) {
                $new_entries++;
            }
        }
        foreach ($offers as $offer_id => $_offer) {
            if (($selected_offer_actions[$offer_id] ?? self::OFFER_IGNORE) === self::OFFER_MOVE) {
                $new_entries++;
            }
        }
        if ($source_data && $selected_data_action !== self::DATA_IGNORE) {
            $new_entries += count($source_data);
        }

        $base_reserve = $this->ReserveCalculator->fetchBaseReserveXlm();
        $available = $this->ReserveCalculator->calculateAvailableXlm($TargetAccount, $base_reserve);
        $required = $this->ReserveCalculator->requiredReserveForNewEntries($new_entries, $base_reserve);
        $missing = bccomp($required, $available, 7) > 0 ? bcsub($required, $available, 7) : '0.0000000';

        return [
            'new_entries' => $new_entries,
            'base_reserve' => $base_reserve,
            'available' => $available,
            'available_short' => $this->formatXlmShort($available),
            'required' => $required,
            'required_short' => $this->formatXlmShort($required),
            'missing' => $missing,
            'missing_rounded' => (string) max(1, (int) ceil((float) $missing)),
        ];
    }

    private function formatXlmShort(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function dataRows(AccountDataResponse $Data): array
    {
        $result = [];
        foreach ($Data->getKeys() as $key) {
            $result[] = [
                'key' => $key,
                'value' => $Data->get($key),
            ];
        }

        return $result;
    }

    private function assetView(Asset $Asset): array
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            return [
                'label' => 'XLM',
                'url' => '/tokens/XLM',
            ];
        }

        if (!$Asset instanceof AssetTypeCreditAlphanum) {
            return [
                'label' => $Asset->getType(),
                'url' => null,
            ];
        }

        return [
            'label' => $Asset->getCode(),
            'url' => '/tokens/' . rawurlencode($Asset->getCode() . '-' . $Asset->getIssuer()),
            'issuer' => $Asset->getIssuer(),
        ];
    }

    private function assetKey(Asset $Asset): ?string
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            return null;
        }
        if (!$Asset instanceof AssetTypeCreditAlphanum) {
            return null;
        }

        return $Asset->getCode() . '-' . $Asset->getIssuer();
    }

    private function assetIsIssuedBy(Asset $Asset, string $account_id): bool
    {
        return $Asset instanceof AssetTypeCreditAlphanum && $Asset->getIssuer() === $account_id;
    }

    private function balanceAssetKey(AccountBalanceResponse $Balance): ?string
    {
        if ($Balance->getAssetType() === Asset::TYPE_NATIVE) {
            return null;
        }

        return $Balance->getAssetCode() . '-' . $Balance->getAssetIssuer();
    }

    private function assetFromToken(array $token): Asset
    {
        return Asset::createNonNativeAsset($token['code'], $token['issuer']);
    }

    private function tokenActionOpens(string $action): bool
    {
        return in_array($action, [self::TOKEN_OPEN, self::TOKEN_TRANSFER, self::TOKEN_CLOSE], true);
    }

    private function tokenActionTransfers(string $action): bool
    {
        return in_array($action, [self::TOKEN_TRANSFER, self::TOKEN_CLOSE], true);
    }

    private function issuerRequiresAuthorization(array $token): bool
    {
        $issuer = (string) $token['issuer'];
        if (array_key_exists($issuer, $this->issuer_auth_required_cache)) {
            return $this->issuer_auth_required_cache[$issuer];
        }

        try {
            $result = $this->Stellar->requestAccount($issuer)->getFlags()->isAuthRequired();
        } catch (\Throwable) {
            $result = true;
        }

        $this->issuer_auth_required_cache[$issuer] = $result;
        return $result;
    }

    private function hasMovedOffers(array $analysis): bool
    {
        foreach ($analysis['selected_offer_actions'] as $action) {
            if ($action === self::OFFER_MOVE) {
                return true;
            }
        }

        return false;
    }
}
