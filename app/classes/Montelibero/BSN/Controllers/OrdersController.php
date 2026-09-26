<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Montelibero\BSN\StellarTomlImageManager;
use Montelibero\BSN\TokenLabelFormatter;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\ManageBuyOfferOperationBuilder;
use Soneso\StellarSDK\ManageSellOfferOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Offers\OfferResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class OrdersController
{
    public function __construct(
        private readonly BSN $BSN,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentContacts $CurrentContacts,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly Translator $Translator,
        private readonly TokensController $TokensController,
        private readonly TokenLabelFormatter $TokenLabelFormatter,
        private readonly StellarTomlImageManager $TomlImageManager,
        private readonly StellarAccountReserveCalculator $ReserveCalculator,
        private readonly Container $Container,
    ) {
    }

    public function Orders(): ?string
    {
        $account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tools/orders'),
                302
            );
            return null;
        }

        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $errors = [];
        $orders = $this->loadOrders($account_id, $errors);
        $posted_orders = is_array($_POST['orders'] ?? null) ? $_POST['orders'] : [];
        $changes = [];
        $signing_form = null;
        $no_changes = false;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $changes = $this->applyPostedOrders($orders, $posted_orders, $errors);
            if (!$errors && $changes) {
                $signing_form = $this->buildSigningForm($account_id, $changes, $errors);
            } elseif (!$errors) {
                $no_changes = true;
            }
        }

        return $this->Twig->render('tools_orders.twig', [
            'account' => $this->CurrentContacts->serialize($this->BSN->makeAccountById($account_id)),
            'account_id' => $account_id,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'order_groups' => $this->groupOrders($orders),
            'changes' => $changes,
            'signing_form' => $signing_form,
            'no_changes' => $no_changes,
            'errors' => $errors,
        ]);
    }

    public function NewOrder(): ?string
    {
        $account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tools/orders/new'),
                302
            );
            return null;
        }

        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $errors = [];
        $signing_form = null;
        $preview = null;
        $Account = null;
        $tokens = [];
        $is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        try {
            $Account = $this->Stellar->requestAccount($account_id);
            $tokens = $this->buildTokenOptions($Account);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_orders.errors.account_not_found');
        }

        $values = $this->defaultNewOrderValues($tokens);
        if (!$is_post) {
            $values = $this->completeNewOrderValues(
                $this->applyNewOrderGetParams($values, $tokens, $_GET)
            );
        }

        if ($is_post) {
            $values = [
                'selling' => (string) ($_POST['selling'] ?? ''),
                'buying' => (string) ($_POST['buying'] ?? ''),
                'selling_amount' => trim((string) ($_POST['selling_amount'] ?? '')),
                'buying_amount' => trim((string) ($_POST['buying_amount'] ?? '')),
                'selling_rate' => trim((string) ($_POST['selling_rate'] ?? '')),
                'buying_rate' => trim((string) ($_POST['buying_rate'] ?? '')),
                'amount_source' => (string) ($_POST['amount_source'] ?? ''),
                'rate_source' => (string) ($_POST['rate_source'] ?? ''),
                'source_order' => (string) ($_POST['source_order'] ?? ''),
            ];
            if (($_POST['action'] ?? '') === 'swap') {
                $values = $this->swapNewOrderValues($values);
            } else {
                $values = $this->completeNewOrderValues($values);
                $prepared = $this->prepareNewOrder($Account, $tokens, $values, $errors);
                if ($prepared !== null && !$errors) {
                    $values = $prepared['form_values'];
                    $preview = $prepared['preview'];
                    $signing_form = $this->buildNewOrderSigningForm($Account, $prepared, $errors);
                }
            }
        }

        return $this->Twig->render('tools_orders_new.twig', [
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'tokens' => $tokens,
            'values' => $values,
            'errors' => $errors,
            'preview' => $preview,
            'signing_form' => $signing_form,
        ]);
    }

    /**
     * @return array<string, array>
     */
    private function buildTokenOptions(AccountResponse $Account): array
    {
        $tokens = [];
        $available_xlm = null;

        foreach ($Account->getBalances() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse) {
                continue;
            }

            $Asset = $this->assetFromBalance($Balance);
            if ($Asset === null) {
                continue;
            }
            if ($Balance->getAssetType() !== Asset::TYPE_NATIVE && $Balance->getIsAuthorized() === false) {
                continue;
            }

            if ($Balance->getAssetType() === Asset::TYPE_NATIVE) {
                if ($available_xlm === null) {
                    $available_xlm = $this->ReserveCalculator->calculateAvailableXlm($Account);
                    if (bccomp($available_xlm, '0', 7) < 0) {
                        $available_xlm = '0.0000000';
                    }
                }
                $available = $available_xlm;
            } else {
                $available = bcsub($Balance->getBalance(), $Balance->getSellingLiabilities() ?? '0.0000000', 7);
                if (bccomp($available, '0', 7) < 0) {
                    $available = '0.0000000';
                }
            }

            $token = $this->assetView($Asset);
            $key = $this->assetKey($token);
            $token += [
                'key' => $key,
                'asset' => $Asset,
                'balance' => $this->stellarDecimal($Balance->getBalance()),
                'available' => $this->stellarDecimal($available),
                'available_label' => $this->shortDecimal($this->stellarDecimal($available)),
                'available_unlimited' => false,
                'selling_disabled' => bccomp($available, '0', 7) <= 0,
            ];
            $token['display_label'] = $this->TokenLabelFormatter->formatToken($token);
            $token['option_label'] = $token['display_label'] . ' (' . $token['available_label'] . ')';
            $tokens[$key] = $token;
        }

        $issued_tokens = $this->buildIssuedTokenOptions($Account->getAccountId(), $tokens);
        uasort($tokens, static function (array $a, array $b): int {
            return strcasecmp(($a['code'] ?? $a['label'] ?? '') . '-' . ($a['issuer'] ?? ''), ($b['code'] ?? $b['label'] ?? '') . '-' . ($b['issuer'] ?? ''));
        });

        foreach ($issued_tokens as $key => $token) {
            $tokens[$key] = $token;
        }

        return $tokens;
    }

    /**
     * @param array<string, array> $existing_tokens
     * @return array<string, array>
     */
    private function buildIssuedTokenOptions(string $issuer, array $existing_tokens): array
    {
        $tokens = [];

        try {
            $page = $this->Stellar->assets()->forAssetIssuer($issuer)->limit(200)->execute();
            do {
                foreach ($page->getAssets() as $AssetResponse) {
                    $code = $AssetResponse->getAssetCode();
                    $asset_issuer = $AssetResponse->getAssetIssuer();
                    if ($code === null || $asset_issuer === null) {
                        continue;
                    }

                    $Asset = Asset::createNonNativeAsset($code, $asset_issuer);
                    $token = $this->assetView($Asset);
                    $key = $this->assetKey($token);
                    if (isset($existing_tokens[$key]) || isset($tokens[$key])) {
                        continue;
                    }

                    $token += [
                        'key' => $key,
                        'asset' => $Asset,
                        'balance' => '0.0000000',
                        'available' => null,
                        'available_label' => '∞',
                        'available_unlimited' => true,
                        'selling_disabled' => false,
                        'is_issued_by_current_account' => true,
                    ];
                    $token['display_label'] = $this->TokenLabelFormatter->formatToken($token);
                    $token['option_label'] = $token['display_label'] . ' (∞)';
                    $tokens[$key] = $token;
                }
                $page = $page->getNextPage();
            } while ($page !== null && $page->getAssets()->count() > 0);
        } catch (\Throwable) {
            return [];
        }

        uasort($tokens, static function (array $a, array $b): int {
            return strcasecmp(($a['code'] ?? $a['label'] ?? '') . '-' . ($a['issuer'] ?? ''), ($b['code'] ?? $b['label'] ?? '') . '-' . ($b['issuer'] ?? ''));
        });

        return $tokens;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function swapNewOrderValues(array $values): array
    {
        foreach ([['selling', 'buying'], ['selling_amount', 'buying_amount'], ['selling_rate', 'buying_rate']] as [$left, $right]) {
            [$values[$left], $values[$right]] = [$values[$right], $values[$left]];
        }
        foreach (['amount_source', 'rate_source', 'source_order'] as $field) {
            $values[$field] = strtr($values[$field], ['selling' => 'buying', 'buying' => 'selling']);
        }

        return $values;
    }

    /**
     * @param array<string, array> $tokens
     */
    private function defaultNewOrderValues(array $tokens): array
    {
        $selling = '';
        foreach ($tokens as $key => $token) {
            if (!$token['selling_disabled']) {
                $selling = $key;
                break;
            }
        }

        $buying = '';
        foreach ($tokens as $key => $token) {
            if ($key !== $selling) {
                $buying = $key;
                break;
            }
        }

        return [
            'selling' => $selling,
            'buying' => $buying,
            'selling_amount' => '',
            'buying_amount' => '',
            'selling_rate' => '',
            'buying_rate' => '',
            'amount_source' => '',
            'rate_source' => '',
            'source_order' => '',
        ];
    }

    /**
     * @param array<string, array> $tokens
     * @param array<string, mixed> $params
     */
    private function applyNewOrderGetParams(array $values, array $tokens, array $params): array
    {
        $sell = $this->resolveTokenParam($params['sell'] ?? null, $tokens, true);
        if ($sell !== null) {
            $values['selling'] = $sell;
        }

        $buy = $this->resolveTokenParam($params['buy'] ?? null, $tokens, false);
        if ($buy !== null) {
            $values['buying'] = $buy;
        }

        $field_params = [
            'selling_amount' => 'sell_amount',
            'buying_amount' => 'buy_amount',
            'selling_rate' => 'sell_rate',
            'buying_rate' => 'buy_rate',
        ];
        foreach ($field_params as $field => $param) {
            if (isset($params[$param]) && is_scalar($params[$param])) {
                $values[$field] = trim((string) $params[$param]);
            }
        }

        if ($values['selling_amount'] === '' && isset($params['amount']) && is_scalar($params['amount'])) {
            $values['selling_amount'] = trim((string) $params['amount']);
        }
        if (
            $values['selling_rate'] === ''
            && $values['buying_rate'] === ''
            && isset($params['price'])
            && is_scalar($params['price'])
        ) {
            $values['selling_rate'] = '1';
            $values['buying_rate'] = trim((string) $params['price']);
            $values['rate_source'] = 'buying';
        }

        $has_selling_amount = $values['selling_amount'] !== '';
        $has_buying_amount = $values['buying_amount'] !== '';
        $values['amount_source'] = $has_selling_amount && $has_buying_amount
            ? 'both'
            : ($has_selling_amount ? 'selling' : ($has_buying_amount ? 'buying' : ''));

        if ($values['rate_source'] === '') {
            $has_selling_rate = $values['selling_rate'] !== '';
            $has_buying_rate = $values['buying_rate'] !== '';
            $values['rate_source'] = $has_selling_rate && $has_buying_rate
                ? 'both'
                : ($has_selling_rate ? 'selling' : ($has_buying_rate ? 'buying' : ''));
        }

        return $values;
    }

    /**
     * @param array<string, array> $tokens
     */
    private function resolveTokenParam(mixed $value, array $tokens, bool $for_selling): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (isset($tokens[$value]) && (!$for_selling || !$tokens[$value]['selling_disabled'])) {
            return $value;
        }

        foreach ($tokens as $key => $token) {
            if ($for_selling && $token['selling_disabled']) {
                continue;
            }
            if (isset($token['code']) && strcasecmp((string) $token['code'], $value) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function completeNewOrderValues(array $values): array
    {
        $ignored_errors = [];
        return $this->resolveNewOrderNumbers($values, $ignored_errors, false) ?? $values;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveNewOrderNumbers(array $values, array &$errors, bool $require_complete): ?array
    {
        $fields = ['selling_amount', 'buying_amount', 'selling_rate', 'buying_rate'];
        $raw = [];
        $normalized = [];
        $form_values = $values;
        $invalid = false;

        foreach ($fields as $field) {
            $raw[$field] = trim((string) ($values[$field] ?? ''));
            $normalized[$field] = $raw[$field] === '' ? null : $this->normalizePostedDecimal($raw[$field]);
            if (
                $raw[$field] !== ''
                && ($normalized[$field] === null || bccomp($normalized[$field], '0', 7) <= 0)
            ) {
                $invalid = true;
                if ($require_complete) {
                    $error_key = str_contains($field, 'rate')
                        ? 'tools_orders_new.errors.invalid_rate'
                        : 'tools_orders_new.errors.invalid_amount';
                    $errors[] = $this->Translator->trans($error_key);
                }
                continue;
            }

            if ($normalized[$field] !== null) {
                $form_values[$field] = $this->shortDecimal($normalized[$field]);
            }
        }

        if ($invalid) {
            return $require_complete ? null : $form_values;
        }

        $selling_amount = $normalized['selling_amount'];
        $buying_amount = $normalized['buying_amount'];
        $selling_rate = $normalized['selling_rate'];
        $buying_rate = $normalized['buying_rate'];

        $source_order = $this->normalizeNewOrderSourceOrder(
            (string) ($values['source_order'] ?? ''),
            $normalized,
        );
        if ($source_order !== []) {
            $normalized = $this->resolveNewOrderSourceValues($normalized, $source_order);
            $selling_amount = $normalized['selling_amount'];
            $buying_amount = $normalized['buying_amount'];
            $selling_rate = $normalized['selling_rate'];
            $buying_rate = $normalized['buying_rate'];

            foreach ($fields as $field) {
                $form_values[$field] = $normalized[$field] === null
                    ? ''
                    : $this->shortDecimal($normalized[$field]);
            }
        }

        $amount_source = (string) ($values['amount_source'] ?? '');
        if ($source_order !== []) {
            $amount_source = $this->newOrderGroupSource($source_order, 'selling_amount', 'buying_amount');
        } elseif (!in_array($amount_source, ['selling', 'buying', 'both'], true)) {
            $amount_source = $selling_amount !== null && $buying_amount !== null
                ? 'both'
                : ($selling_amount !== null ? 'selling' : ($buying_amount !== null ? 'buying' : ''));
        }
        if ($amount_source === 'selling' && $selling_amount === null) {
            $amount_source = $buying_amount !== null ? 'buying' : '';
        } elseif ($amount_source === 'buying' && $buying_amount === null) {
            $amount_source = $selling_amount !== null ? 'selling' : '';
        } elseif ($amount_source === 'both' && ($selling_amount === null || $buying_amount === null)) {
            $amount_source = $selling_amount !== null ? 'selling' : ($buying_amount !== null ? 'buying' : '');
        }

        $rate_source = (string) ($values['rate_source'] ?? '');
        if ($source_order !== []) {
            $rate_source = $this->newOrderGroupSource($source_order, 'selling_rate', 'buying_rate');
            if ($rate_source === '' && $amount_source === 'both') {
                $rate_source = 'calculated';
            }
        } elseif (!in_array($rate_source, ['selling', 'buying', 'both', 'calculated'], true)) {
            $rate_source = $selling_rate !== null && $buying_rate !== null
                ? 'both'
                : ($selling_rate !== null ? 'selling' : ($buying_rate !== null ? 'buying' : ''));
        }

        $form_values['amount_source'] = $amount_source;
        $form_values['rate_source'] = $rate_source;
        $form_values['source_order'] = implode(',', $source_order);

        if ($amount_source === '') {
            if ($require_complete) {
                $errors[] = $this->Translator->trans('tools_orders_new.errors.not_enough_values');
                return null;
            }
            return $form_values;
        }

        $has_rate = $selling_rate !== null && $buying_rate !== null;
        $has_partial_rate = ($selling_rate !== null) !== ($buying_rate !== null);

        if (
            $amount_source === 'both'
            && $selling_amount !== null
            && $buying_amount !== null
            && !$has_rate
            && !$has_partial_rate
        ) {
            [$selling_rate, $buying_rate] = $this->newOrderRatePair($selling_amount, $buying_amount);
            $rate_source = 'calculated';
        } elseif (!$has_rate) {
            if ($require_complete) {
                $errors[] = $this->Translator->trans('tools_orders_new.errors.not_enough_values');
                return null;
            }
            return $form_values;
        }

        if ($amount_source === 'selling' && $selling_amount !== null) {
            $buying_amount = $this->newOrderBuyingAmount($selling_amount, $selling_rate, $buying_rate);
        } elseif ($amount_source === 'buying' && $buying_amount !== null) {
            $selling_amount = $this->newOrderSellingAmount($buying_amount, $selling_rate, $buying_rate);
        } elseif ($selling_amount !== null && $buying_amount !== null) {
            $expected_buying_amount = $this->newOrderBuyingAmount($selling_amount, $selling_rate, $buying_rate);
            if (
                $rate_source !== 'calculated'
                && $this->newOrderDecimalsDiffer($buying_amount, $expected_buying_amount)
            ) {
                if ($require_complete) {
                    $errors[] = $this->Translator->trans('tools_orders_new.errors.inconsistent_values');
                    return null;
                }
                return $form_values;
            }
        } else {
            if ($require_complete) {
                $errors[] = $this->Translator->trans('tools_orders_new.errors.not_enough_values');
                return null;
            }
            return $form_values;
        }

        if (
            $selling_amount === null
            || $buying_amount === null
            || $selling_rate === null
            || $buying_rate === null
            || bccomp($selling_amount, '0', 7) <= 0
            || bccomp($buying_amount, '0', 7) <= 0
            || bccomp($selling_rate, '0', 7) <= 0
            || bccomp($buying_rate, '0', 7) <= 0
        ) {
            if ($require_complete) {
                $errors[] = $this->Translator->trans('tools_orders_new.errors.invalid_amount');
                return null;
            }
            return $form_values;
        }

        return array_merge($form_values, [
            'selling' => (string) ($values['selling'] ?? ''),
            'buying' => (string) ($values['buying'] ?? ''),
            'selling_amount' => $this->shortDecimal($selling_amount),
            'buying_amount' => $this->shortDecimal($buying_amount),
            'selling_rate' => $this->shortDecimal($selling_rate),
            'buying_rate' => $this->shortDecimal($buying_rate),
            'amount_source' => $amount_source,
            'rate_source' => $rate_source,
            'source_order' => implode(',', $source_order),
        ]);
    }

    /**
     * @param array<string, string|null> $values
     * @return list<string>
     */
    private function normalizeNewOrderSourceOrder(string $value, array $values): array
    {
        $allowed = ['selling_amount', 'buying_amount', 'selling_rate', 'buying_rate'];
        $order = [];

        foreach (explode(',', $value) as $field) {
            $field = trim($field);
            if (!in_array($field, $allowed, true) || ($values[$field] ?? null) === null) {
                continue;
            }

            $order = array_values(array_filter($order, static fn (string $item): bool => $item !== $field));
            $order[] = $field;
        }

        return array_slice($order, -3);
    }

    /**
     * @param array<string, string|null> $values
     * @param list<string> $source_order
     * @return array<string, string|null>
     */
    private function resolveNewOrderSourceValues(array $values, array $source_order): array
    {
        $source_values = [];
        foreach ($source_order as $field) {
            $source_values[$field] = $values[$field];
        }

        $values = [
            'selling_amount' => $source_values['selling_amount'] ?? null,
            'buying_amount' => $source_values['buying_amount'] ?? null,
            'selling_rate' => $source_values['selling_rate'] ?? null,
            'buying_rate' => $source_values['buying_rate'] ?? null,
        ];

        if (count($source_order) === 1) {
            if (isset($source_values['selling_rate'])) {
                $values['buying_rate'] = '1.0000000';
            } elseif (isset($source_values['buying_rate'])) {
                $values['selling_rate'] = '1.0000000';
            }

            return $values;
        }

        if (count($source_order) === 2) {
            if ($values['selling_amount'] !== null && $values['buying_amount'] !== null) {
                [$values['selling_rate'], $values['buying_rate']] = $this->newOrderRatePair(
                    $values['selling_amount'],
                    $values['buying_amount'],
                );
                return $values;
            }

            if ($values['selling_rate'] !== null && $values['buying_rate'] !== null) {
                return $values;
            }

            if ($values['selling_rate'] !== null) {
                $values['buying_rate'] = '1.0000000';
            } elseif ($values['buying_rate'] !== null) {
                $values['selling_rate'] = '1.0000000';
            }
        }

        if (
            $values['selling_amount'] !== null
            && $values['selling_rate'] !== null
            && $values['buying_rate'] !== null
        ) {
            $values['buying_amount'] = $this->newOrderBuyingAmount(
                $values['selling_amount'],
                $values['selling_rate'],
                $values['buying_rate'],
            );
        } elseif (
            $values['buying_amount'] !== null
            && $values['selling_rate'] !== null
            && $values['buying_rate'] !== null
        ) {
            $values['selling_amount'] = $this->newOrderSellingAmount(
                $values['buying_amount'],
                $values['selling_rate'],
                $values['buying_rate'],
            );
        } elseif (
            $values['selling_amount'] !== null
            && $values['buying_amount'] !== null
            && $values['selling_rate'] !== null
        ) {
            $values['buying_rate'] = $this->stellarDecimal(bcdiv(
                bcmul($values['buying_amount'], $values['selling_rate'], 14),
                $values['selling_amount'],
                14,
            ));
        } elseif (
            $values['selling_amount'] !== null
            && $values['buying_amount'] !== null
            && $values['buying_rate'] !== null
        ) {
            $values['selling_rate'] = $this->stellarDecimal(bcdiv(
                bcmul($values['selling_amount'], $values['buying_rate'], 14),
                $values['buying_amount'],
                14,
            ));
        }

        return $values;
    }

    /**
     * @param list<string> $source_order
     */
    private function newOrderGroupSource(
        array $source_order,
        string $selling_field,
        string $buying_field,
    ): string {
        $has_selling = in_array($selling_field, $source_order, true);
        $has_buying = in_array($buying_field, $source_order, true);

        if ($has_selling && $has_buying) {
            return 'both';
        }
        if ($has_selling) {
            return 'selling';
        }
        if ($has_buying) {
            return 'buying';
        }

        return '';
    }

    private function newOrderBuyingAmount(string $selling_amount, string $selling_rate, string $buying_rate): string
    {
        return $this->stellarDecimal(bcdiv(bcmul($selling_amount, $buying_rate, 14), $selling_rate, 14));
    }

    private function newOrderSellingAmount(string $buying_amount, string $selling_rate, string $buying_rate): string
    {
        return $this->stellarDecimal(bcdiv(bcmul($buying_amount, $selling_rate, 14), $buying_rate, 14));
    }

    /**
     * @return array{string, string}
     */
    private function newOrderRatePair(string $selling_amount, string $buying_amount): array
    {
        if (bccomp($selling_amount, $buying_amount, 7) >= 0) {
            return [$this->stellarDecimal(bcdiv($selling_amount, $buying_amount, 14)), '1.0000000'];
        }

        return ['1.0000000', $this->stellarDecimal(bcdiv($buying_amount, $selling_amount, 14))];
    }

    private function newOrderDecimalsDiffer(string $left, string $right): bool
    {
        $difference = bcsub($left, $right, 7);
        if (str_starts_with($difference, '-')) {
            $difference = substr($difference, 1);
        }

        return bccomp($difference, '0.0000001', 7) > 0;
    }

    /**
     * @param array<string, array> $tokens
     */
    private function prepareNewOrder(
        ?AccountResponse $Account,
        array $tokens,
        array $values,
        array &$errors,
    ): ?array
    {
        if ($Account === null) {
            return null;
        }

        $selling_key = (string) ($values['selling'] ?? '');
        $buying_key = (string) ($values['buying'] ?? '');
        $Selling = $tokens[$selling_key] ?? null;
        $Buying = $tokens[$buying_key] ?? null;
        if ($Selling === null) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.invalid_selling');
        }
        if ($Buying === null) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.invalid_buying');
        }
        if ($Selling !== null && $Buying !== null && $selling_key === $buying_key) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.same_assets');
        }

        $form_values = $this->resolveNewOrderNumbers($values, $errors, true);
        $selling_amount = $form_values !== null
            ? $this->normalizePostedDecimal($form_values['selling_amount'])
            : null;
        if (
            $Selling !== null
            && $selling_amount !== null
            && isset($Selling['available'])
            && bccomp($selling_amount, $Selling['available'], 7) > 0
        ) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.amount_too_big', [
                '%available%' => $Selling['available_label'],
                '%asset%' => $Selling['code'] ?? $Selling['label'] ?? '',
            ]);
        }

        if ($errors || $Selling === null || $Buying === null || $form_values === null) {
            return null;
        }

        $selling_amount = $this->normalizePostedDecimal($form_values['selling_amount']);
        $buying_amount = $this->normalizePostedDecimal($form_values['buying_amount']);
        $selling_rate = $this->normalizePostedDecimal($form_values['selling_rate']);
        $buying_rate = $this->normalizePostedDecimal($form_values['buying_rate']);
        if ($selling_amount === null || $buying_amount === null || $selling_rate === null || $buying_rate === null) {
            return null;
        }

        $uses_buy_operation = $form_values['amount_source'] === 'buying';
        $operation_price = $form_values['amount_source'] === 'both'
            && $form_values['rate_source'] === 'calculated'
            ? bcdiv($buying_amount, $selling_amount, 14)
            : bcdiv(
                $uses_buy_operation ? $selling_rate : $buying_rate,
                $uses_buy_operation ? $buying_rate : $selling_rate,
                14,
            );

        return [
            'selling' => $Selling,
            'buying' => $Buying,
            'uses_buy_operation' => $uses_buy_operation,
            'operation_amount' => $uses_buy_operation ? $buying_amount : $selling_amount,
            'operation_price' => $operation_price,
            'form_values' => $form_values,
            'preview' => [
                'selling' => $this->summaryToken($Selling, $selling_amount),
                'buying' => $this->summaryToken($Buying, $buying_amount),
                'selling_rate' => $this->shortDecimal($selling_rate),
                'buying_rate' => $this->shortDecimal($buying_rate),
            ],
        ];
    }

    private function buildNewOrderSigningForm(AccountResponse $Account, array $prepared, array &$errors): ?string
    {
        try {
            $Transaction = new TransactionBuilder($Account);
            $Transaction->setMaxOperationFee(10000);
            $Transaction->addMemo(Memo::text('New order'));
            $Transaction->addOperation($this->buildNewOrderOperation($prepared));
            $xdr = $Transaction->build()->toEnvelopeXdrBase64();
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.transaction_failed');
            return null;
        }

        return $this->Container->get(SignController::class)->SignTransaction(
            $xdr,
            null,
            $this->Translator->trans('tools_orders_new.signing.description'),
            $this->Translator->trans('tools_orders_new.signing.title')
        );
    }

    private function buildNewOrderOperation(array $prepared): AbstractOperation
    {
        if ($prepared['uses_buy_operation']) {
            return (new ManageBuyOfferOperationBuilder(
                $prepared['selling']['asset'],
                $prepared['buying']['asset'],
                $prepared['operation_amount'],
                $prepared['operation_price']
            ))->setOfferId(0)->build();
        }

        return (new ManageSellOfferOperationBuilder(
            $prepared['selling']['asset'],
            $prepared['buying']['asset'],
            $prepared['operation_amount'],
            $prepared['operation_price']
        ))->setOfferId(0)->build();
    }

    /**
     * @return list<array>
     */
    private function loadOrders(string $account_id, array &$errors): array
    {
        $orders = [];

        try {
            $page = $this->Stellar->offers()->forAccount($account_id)->limit(200)->execute();
            do {
                foreach ($page->getOffers() as $Offer) {
                    $orders[] = $this->orderView($Offer);
                }
                $page = $page->getNextPage();
            } while ($page !== null && $page->getOffers()->count() > 0);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_orders.errors.offers_not_loaded');
        }

        return $orders;
    }

    /**
     * @param list<array> $orders
     * @return list<array>
     */
    private function groupOrders(array $orders): array
    {
        $groups = [];

        foreach ($orders as $order) {
            $selling_key = $this->assetKey($order['selling']);
            $buying_key = $this->assetKey($order['buying']);
            $pair_parts = [$selling_key, $buying_key];
            sort($pair_parts, SORT_STRING);
            $pair_key = implode('|', $pair_parts);

            if (!isset($groups[$pair_key])) {
                $groups[$pair_key] = [
                    'selling' => $order['selling'],
                    'buying' => $order['buying'],
                    'directions' => [],
                ];
            }

            $direction_key = $selling_key . '>' . $buying_key;
            if (!isset($groups[$pair_key]['directions'][$direction_key])) {
                $groups[$pair_key]['directions'][$direction_key] = [
                    'selling' => $order['selling'],
                    'buying' => $order['buying'],
                    'orders' => [],
                ];
            }

            $groups[$pair_key]['directions'][$direction_key]['orders'][] = $order;
        }

        foreach ($groups as &$group) {
            $group['directions'] = array_values($group['directions']);
        }
        unset($group);

        return array_values($groups);
    }

    private function orderView(OfferResponse $Offer): array
    {
        $price = $Offer->getPrice();
        $amount = $this->stellarDecimal($Offer->getAmount());
        $normalized_price = $this->stellarDecimal($price);
        $buying_amount = $this->stellarDecimal(bcmul($Offer->getAmount(), $price, 7));

        return [
            'id' => $Offer->getOfferId(),
            'selling' => $this->assetView($Offer->getSelling()),
            'buying' => $this->assetView($Offer->getBuying()),
            'amount' => $amount,
            'price' => $normalized_price,
            'buying_amount' => $buying_amount,
            'form_amount' => $this->shortDecimal($amount),
            'form_price' => $this->shortDecimal($normalized_price),
            'form_reverse_price' => $this->reversePrice($normalized_price),
            'form_buying_amount' => $this->shortDecimal($buying_amount),
            'amount_changed' => false,
            'price_changed' => false,
            'offer_response' => $Offer,
        ];
    }

    /**
     * @param list<array> $orders
     * @param array<string, mixed> $posted_orders
     * @return list<array>
     */
    private function applyPostedOrders(array &$orders, array $posted_orders, array &$errors): array
    {
        $changes = [];

        foreach ($orders as &$order) {
            $offer_id = (string) $order['id'];
            $posted = $posted_orders[$offer_id] ?? null;
            if (!is_array($posted)) {
                continue;
            }

            $amount = $this->normalizePostedDecimal($posted['amount'] ?? null);
            $price = $this->normalizePostedDecimal($posted['price'] ?? null);
            if ($amount === null) {
                $errors[] = $this->Translator->trans('tools_orders.errors.invalid_amount', ['%offer%' => $offer_id]);
                $amount = $order['amount'];
            }
            if ($price === null || bccomp($price, '0', 7) <= 0) {
                $errors[] = $this->Translator->trans('tools_orders.errors.invalid_price', ['%offer%' => $offer_id]);
                $price = $order['price'];
            }

            $order['form_amount'] = $this->shortDecimal($amount);
            $order['form_price'] = $this->shortDecimal($price);
            $order['form_reverse_price'] = $this->reversePrice($price);
            $order['form_buying_amount'] = $this->shortDecimal($this->stellarDecimal(bcmul($amount, $price, 7)));
            $order['amount_changed'] = $amount !== $order['amount'];
            $order['price_changed'] = $price !== $order['price'];

            if (!$order['amount_changed'] && !$order['price_changed']) {
                continue;
            }

            $changes[] = $this->changeView($order, $amount, $price);
        }
        unset($order);

        return $changes;
    }

    private function normalizePostedDecimal(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^\d+(?:\.\d{0,7})?$/', $value)) {
            return null;
        }

        return $this->stellarDecimal($value);
    }

    private function changeView(array $order, string $new_amount, string $new_price): array
    {
        $new_buying_amount = $this->stellarDecimal(bcmul($new_amount, $new_price, 7));
        $deletes = bccomp($new_amount, '0', 7) === 0;

        return [
            'id' => $order['id'],
            'type' => $deletes ? 'delete' : 'update',
            'selling' => $this->summaryToken($order['selling'], $order['amount']),
            'buying' => $this->summaryToken($order['buying'], $order['buying_amount']),
            'buying_code' => $order['buying']['code'] ?? $order['buying']['label'] ?? '',
            'amount_changed' => $order['amount_changed'],
            'price_changed' => $order['price_changed'],
            'old_amount' => $this->shortDecimal($order['amount']),
            'new_amount' => $this->shortDecimal($new_amount),
            'old_price' => $this->shortDecimal($order['price']),
            'new_price' => $this->shortDecimal($new_price),
            'new_buying_amount' => $this->shortDecimal($new_buying_amount),
            'operation_amount' => $deletes ? '0' : $new_amount,
            'operation_price' => $deletes ? $order['price'] : $new_price,
            'offer_response' => $order['offer_response'],
        ];
    }

    /**
     * @param list<array> $changes
     */
    private function buildSigningForm(string $account_id, array $changes, array &$errors): ?string
    {
        try {
            $Account = $this->Stellar->requestAccount($account_id);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_orders.errors.account_not_found');
            return null;
        }

        $Transaction = new TransactionBuilder($Account);
        $Transaction->setMaxOperationFee(10000);
        $Transaction->addMemo(Memo::text('Orders update'));
        foreach ($changes as $change) {
            /** @var OfferResponse $Offer */
            $Offer = $change['offer_response'];
            $Transaction->addOperation(
                (new ManageSellOfferOperationBuilder(
                    $Offer->getSelling(),
                    $Offer->getBuying(),
                    $change['operation_amount'],
                    $change['operation_price']
                ))->setOfferId((int) $Offer->getOfferId())->build()
            );
        }

        $xdr = $Transaction->build()->toEnvelopeXdrBase64();
        return $this->Container->get(SignController::class)->SignTransaction(
            $xdr,
            null,
            $this->Translator->trans('tools_orders.signing.description', ['%account%' => $account_id]),
            $this->Translator->trans('tools_orders.signing.title')
        );
    }

    private function summaryToken(array $token, string $amount): array
    {
        $result = $token;
        $result['amount'] = $this->shortDecimal($amount);
        return $result;
    }

    private function shortDecimal(string $amount): string
    {
        $amount = rtrim(rtrim($amount, '0'), '.');
        return $amount === '' ? '0' : $amount;
    }

    private function reversePrice(string $price): string
    {
        if (bccomp($price, '0', 7) <= 0) {
            return '—';
        }

        return $this->shortDecimal(number_format(round(1 / (float) $price, 7), 7, '.', ''));
    }

    private function stellarDecimal(string $amount): string
    {
        $parts = explode('.', $amount, 2);
        $int = $parts[0] === '' ? '0' : $parts[0];
        $frac = $parts[1] ?? '';

        return $int . '.' . str_pad(substr($frac, 0, 7), 7, '0');
    }

    private function assetKey(array $asset): string
    {
        $code = (string) ($asset['code'] ?? $asset['label'] ?? '');
        $issuer = (string) ($asset['issuer'] ?? '');

        return $issuer === '' ? $code : $code . '-' . $issuer;
    }

    private function assetFromBalance(AccountBalanceResponse $Balance): ?Asset
    {
        if ($Balance->getAssetType() === Asset::TYPE_NATIVE) {
            return Asset::native();
        }

        $code = $Balance->getAssetCode();
        $issuer = $Balance->getAssetIssuer();
        if ($code === null || $issuer === null) {
            return null;
        }

        return Asset::createNonNativeAsset($code, $issuer);
    }

    private function assetView(Asset $Asset): array
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            return [
                'code' => 'XLM',
                'issuer' => null,
                'url' => '/tokens/XLM',
                'is_known' => true,
            ];
        }

        if (!$Asset instanceof AssetTypeCreditAlphanum) {
            return [
                'label' => $Asset->getType(),
            ];
        }

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
}
