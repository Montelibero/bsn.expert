<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\OpenTrustlinesTransactionBuilder;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class OpenTrustlinesController
{
    private const SCALE = 7;
    private const ANONYMOUS_ISSUER_LOOKUP_LIMIT = 20;

    public function __construct(
        private readonly BSN $BSN,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentContacts $CurrentContacts,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly Translator $Translator,
        private readonly TokensController $TokensController,
        private readonly StellarAccountReserveCalculator $ReserveCalculator,
        private readonly OpenTrustlinesTransactionBuilder $TransactionBuilder,
        private readonly Container $Container,
    ) {
    }

    public function OpenTrustlines(): ?string
    {
        $source_account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$source_account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tools/open_trustlines'),
                302,
            );
            return null;
        }

        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $errors = [];
        $row_errors = [];
        $signing_form = null;
        $preview = null;
        $SourceAccount = null;
        $is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $action = $is_post ? $this->scalarString($_POST['action'] ?? '') : '';
        $trustlines = $is_post
            ? $this->postedTrustlineRows($action === 'build', $errors)
            : [$this->initialTrustlineRow()];

        if ($trustlines === []) {
            $trustlines[] = $this->defaultTrustlineRow();
        }

        try {
            $SourceAccount = $this->Stellar->requestAccount($source_account_id);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_open_trustlines.errors.source_not_found');
        }

        if ($is_post) {
            if ($this->scalarString($_POST['form_complete'] ?? '') !== '1') {
                $errors[] = $this->Translator->trans('tools_open_trustlines.errors.form_truncated');
            } elseif (isset($_POST['remove'])) {
                $remove = filter_var($this->scalarString($_POST['remove']), FILTER_VALIDATE_INT);
                if ($remove !== false && isset($trustlines[$remove])) {
                    array_splice($trustlines, $remove, 1);
                }
                if ($trustlines === []) {
                    $trustlines[] = $this->defaultTrustlineRow();
                }
            } elseif ($action === 'add') {
                if (count($trustlines) >= OpenTrustlinesTransactionBuilder::MAX_OPERATIONS) {
                    $errors[] = $this->Translator->trans('tools_open_trustlines.errors.too_many_operations', [
                        '%maximum%' => (string) OpenTrustlinesTransactionBuilder::MAX_OPERATIONS,
                    ]);
                } else {
                    $trustlines[] = $this->defaultTrustlineRow();
                }
            } elseif ($action === 'build' && $SourceAccount !== null) {
                $prepared = $this->prepareTrustlines($SourceAccount, $trustlines, $row_errors, $errors);
                if ($prepared !== null && $errors === [] && $row_errors === []) {
                    try {
                        $xdr = $this->TransactionBuilder->build($SourceAccount, $prepared['assets']);
                        $preview = $this->buildPreview($source_account_id, $prepared);
                        $signing_form = $this->Container->get(SignController::class)->SignTransaction(
                            $xdr,
                            null,
                            $this->Translator->trans('tools_open_trustlines.signing.description', [
                                '%account%' => $source_account_id,
                            ]),
                            $this->Translator->trans('tools_open_trustlines.signing.title'),
                        );
                    } catch (\Throwable) {
                        $errors[] = $this->Translator->trans('tools_open_trustlines.errors.transaction_failed');
                    }
                }
            }
        }

        return $this->Twig->render('tools_open_trustlines.twig', [
            'account' => $this->accountView($source_account_id),
            'source_account_id' => $source_account_id,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'trustlines' => $trustlines,
            'max_operations' => OpenTrustlinesTransactionBuilder::MAX_OPERATIONS,
            'row_errors' => $row_errors,
            'errors' => $errors,
            'preview' => $preview,
            'signing_form' => $signing_form,
        ]);
    }

    /**
     * @param list<array{asset:string,invalid:bool}> $trustlines
     * @param array<int, list<string>> $row_errors
     * @param list<string> $errors
     * @return array{assets:list<Asset>, items:list<array{code:string,issuer:string,key:string,asset:AssetTypeCreditAlphanum,index:int}>,reserve:array{fee:string,reserve:string,required:string,available:string}}|null
     */
    private function prepareTrustlines(
        AccountResponse $SourceAccount,
        array $trustlines,
        array &$row_errors,
        array &$errors,
    ): ?array {
        if ($trustlines === []) {
            $errors[] = $this->Translator->trans('tools_open_trustlines.errors.no_operations');
            return null;
        }
        if (count($trustlines) > OpenTrustlinesTransactionBuilder::MAX_OPERATIONS) {
            $errors[] = $this->Translator->trans('tools_open_trustlines.errors.too_many_operations', [
                '%maximum%' => (string) OpenTrustlinesTransactionBuilder::MAX_OPERATIONS,
            ]);
            return null;
        }

        $existing = $this->existingTrustlineKeys($SourceAccount);
        $selected = [];
        $items = [];

        foreach ($trustlines as $index => $trustline) {
            if ($trustline['invalid']) {
                $row_errors[$index][] = $this->Translator->trans('tools_open_trustlines.errors.invalid_row');
                continue;
            }

            $parsed = $this->parseTrustlineAsset($trustline['asset']);
            if ($parsed['kind'] !== 'asset') {
                $row_errors[$index][] = $this->Translator->trans(
                    'tools_open_trustlines.errors.' . $parsed['kind'],
                );
                continue;
            }

            if ($parsed['issuer'] === $SourceAccount->getAccountId()) {
                $row_errors[$index][] = $this->Translator->trans('tools_open_trustlines.errors.issuer_is_source');
                continue;
            }
            if (isset($existing[$parsed['key']])) {
                $row_errors[$index][] = $this->Translator->trans('tools_open_trustlines.errors.already_open', [
                    '%asset%' => $parsed['key'],
                ]);
                continue;
            }
            if (isset($selected[$parsed['key']])) {
                $row_errors[$index][] = $this->Translator->trans('tools_open_trustlines.errors.duplicate', [
                    '%asset%' => $parsed['key'],
                ]);
                continue;
            }

            $selected[$parsed['key']] = true;
            $items[] = [
                'code' => $parsed['code'],
                'issuer' => $parsed['issuer'],
                'key' => $parsed['key'],
                'asset' => $parsed['asset'],
                'index' => $index,
            ];
        }

        if ($row_errors !== []) {
            return null;
        }

        $this->validateIssuerAccounts($items, $row_errors, $errors);
        if ($row_errors !== [] || $errors !== []) {
            return null;
        }

        $reserve = $this->validateReserve($SourceAccount, count($items), $errors);
        if ($reserve === null || $errors !== []) {
            return null;
        }

        return [
            'assets' => array_column($items, 'asset'),
            'items' => $items,
            'reserve' => $reserve,
        ];
    }

    /**
     * @return list<array{asset:string,invalid:bool}>
     */
    private function postedTrustlineRows(bool $report_structure_errors, array &$errors): array
    {
        $posted = $_POST['trustlines'] ?? null;
        if (!is_array($posted)) {
            if ($report_structure_errors) {
                $errors[] = $this->Translator->trans('tools_open_trustlines.errors.no_operations');
            }
            return [];
        }

        if (count($posted) > OpenTrustlinesTransactionBuilder::MAX_OPERATIONS) {
            $errors[] = $this->Translator->trans('tools_open_trustlines.errors.too_many_operations', [
                '%maximum%' => (string) OpenTrustlinesTransactionBuilder::MAX_OPERATIONS,
            ]);
            $posted = array_slice($posted, 0, OpenTrustlinesTransactionBuilder::MAX_OPERATIONS, true);
        }

        $rows = [];
        foreach ($posted as $row) {
            $invalid = !is_array($row);
            $row = is_array($row) ? $row : [];
            $rows[] = [
                'asset' => trim($this->scalarString($row['asset'] ?? '')),
                'invalid' => $invalid,
            ];
        }

        return $rows;
    }

    /** @return array{asset:string,invalid:bool} */
    private function initialTrustlineRow(): array
    {
        $row = $this->defaultTrustlineRow();
        $row['asset'] = trim($this->scalarString($_GET['asset'] ?? ''));

        return $row;
    }

    /** @return array{asset:string,invalid:bool} */
    private function defaultTrustlineRow(): array
    {
        return [
            'asset' => '',
            'invalid' => false,
        ];
    }

    /**
     * @return array{kind:'asset',code:string,issuer:string,key:string,asset:AssetTypeCreditAlphanum}|array{kind:'asset_required'|'issuer_without_code'|'asset_invalid'}
     */
    private function parseTrustlineAsset(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['kind' => 'asset_required'];
        }

        if ($this->isValidAccountId(strtoupper($value))) {
            return ['kind' => 'issuer_without_code'];
        }

        $parts = explode('-', $value, 2);
        if (count($parts) !== 2) {
            return ['kind' => 'asset_invalid'];
        }

        $code = trim($parts[0]);
        $issuer = strtoupper(trim($parts[1]));
        if (
            preg_match('/\A[A-Za-z0-9]{1,12}\z/D', $code) !== 1
            || !$this->isValidAccountId($issuer)
        ) {
            return ['kind' => 'asset_invalid'];
        }

        try {
            $Asset = Asset::createNonNativeAsset($code, $issuer);
        } catch (\Throwable) {
            return ['kind' => 'asset_invalid'];
        }
        if (!$Asset instanceof AssetTypeCreditAlphanum) {
            return ['kind' => 'asset_invalid'];
        }

        return [
            'kind' => 'asset',
            'code' => $code,
            'issuer' => $issuer,
            'key' => $code . '-' . $issuer,
            'asset' => $Asset,
        ];
    }

    /**
     * @param list<array{code:string,issuer:string,key:string,asset:AssetTypeCreditAlphanum,index:int}> $items
     * @param array<int, list<string>> $row_errors
     * @param list<string> $errors
     */
    private function validateIssuerAccounts(array $items, array &$row_errors, array &$errors): void
    {
        $by_issuer = [];
        foreach ($items as $item) {
            $by_issuer[$item['issuer']][] = $item;
        }

        if (
            !$this->CurrentUser->isAuthorized()
            && count($by_issuer) > self::ANONYMOUS_ISSUER_LOOKUP_LIMIT
        ) {
            $errors[] = $this->Translator->trans('tools_open_trustlines.errors.anonymous_issuer_limit', [
                '%maximum%' => (string) self::ANONYMOUS_ISSUER_LOOKUP_LIMIT,
            ]);
            return;
        }

        foreach ($by_issuer as $issuer => $issuer_items) {
            try {
                $exists = $this->Stellar->accountExists($issuer);
            } catch (\Throwable) {
                foreach ($issuer_items as $item) {
                    $row_errors[$item['index']][] = $this->Translator->trans(
                        'tools_open_trustlines.errors.issuer_not_checked',
                        ['%issuer%' => $issuer],
                    );
                }
                continue;
            }

            if ($exists) {
                continue;
            }

            foreach ($issuer_items as $item) {
                $row_errors[$item['index']][] = $this->Translator->trans(
                    'tools_open_trustlines.errors.issuer_not_found',
                    ['%issuer%' => $issuer],
                );
            }
        }
    }

    /**
     * @param list<string> $errors
     * @return array{fee:string,reserve:string,required:string,available:string}|null
     */
    private function validateReserve(AccountResponse $SourceAccount, int $trustline_count, array &$errors): ?array
    {
        try {
            $base_reserve = $this->ReserveCalculator->fetchBaseReserveXlm();
            $available = $this->ReserveCalculator->calculateAvailableXlm($SourceAccount, $base_reserve);
            $reserve = $this->ReserveCalculator->requiredReserveForNewEntries($trustline_count, $base_reserve);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_open_trustlines.errors.reserve_not_checked');
            return null;
        }

        $fee = OpenTrustlinesTransactionBuilder::maxFeeXlm($trustline_count);
        $required = bcadd($reserve, $fee, self::SCALE);
        if (bccomp($required, $available, self::SCALE) > 0) {
            $errors[] = $this->Translator->trans('tools_open_trustlines.errors.reserve_missing', [
                '%required%' => $this->shortDecimal($required),
                '%available%' => $this->shortDecimal($available),
            ]);
            return null;
        }

        return [
            'fee' => $fee,
            'reserve' => $reserve,
            'required' => $required,
            'available' => $available,
        ];
    }

    /** @return array<string, true> */
    private function existingTrustlineKeys(AccountResponse $Account): array
    {
        $keys = [];
        foreach ($Account->getBalances() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse || $Balance->getAssetType() === Asset::TYPE_NATIVE) {
                continue;
            }

            $code = $Balance->getAssetCode();
            $issuer = $Balance->getAssetIssuer();
            if ($code === null || $issuer === null) {
                continue;
            }
            $keys[$code . '-' . $issuer] = true;
        }

        return $keys;
    }

    /**
     * @param array{items:list<array{code:string,issuer:string,key:string,asset:AssetTypeCreditAlphanum,index:int}>,reserve:array{fee:string,reserve:string,required:string,available:string}} $prepared
     */
    private function buildPreview(string $source_account_id, array $prepared): array
    {
        $source = $this->accountView($source_account_id);
        $operations = [];
        foreach ($prepared['items'] as $item) {
            $operations[] = [
                'title' => $this->Translator->trans('transactions.operations.change_trust.actions.open'),
                'template' => 'operations/change_trust.twig',
                'source' => $source,
                'data' => [
                    'asset' => $this->assetView($item['code'], $item['issuer'], $item['key']),
                    'limit' => null,
                ],
            ];
        }

        return [
            'operation_count' => count($operations),
            'fee' => $this->shortDecimal($prepared['reserve']['fee']),
            'reserve' => $this->shortDecimal($prepared['reserve']['reserve']),
            'required' => $this->shortDecimal($prepared['reserve']['required']),
            'available' => $this->shortDecimal($prepared['reserve']['available']),
            'operations' => $operations,
        ];
    }

    private function assetView(string $code, string $issuer, string $key): array
    {
        try {
            $is_known = $this->TokensController->getKnownToken($key) !== null;
        } catch (\Throwable) {
            $is_known = false;
        }

        return [
            'code' => $code,
            'issuer' => $issuer,
            'is_known' => $is_known,
            'url' => '/tokens/' . rawurlencode($is_known ? $code : $key),
        ];
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
