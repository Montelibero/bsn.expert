<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Montelibero\BSN\StellarTomlImageManager;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
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

        try {
            $Account = $this->Stellar->requestAccount($account_id);
            $tokens = $this->buildTokenOptions($Account);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_orders.errors.account_not_found');
        }

        $is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $values = $this->defaultNewOrderValues($tokens);
        if (!$is_post) {
            $values = $this->applyNewOrderGetParams($values, $tokens);
        }

        if ($is_post) {
            $values = [
                'selling' => (string) ($_POST['selling'] ?? ''),
                'buying' => (string) ($_POST['buying'] ?? ''),
                'amount' => trim((string) ($_POST['amount'] ?? '')),
                'price' => trim((string) ($_POST['price'] ?? '')),
            ];
            $prepared = $this->prepareNewOrder($Account, $tokens, $values, $errors);
            if ($prepared !== null && !$errors) {
                $preview = $prepared['preview'];
                $signing_form = $this->buildNewOrderSigningForm($Account, $prepared, $errors);
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
            $token['option_label'] = ($token['code'] ?? $token['label'] ?? $key) . ' (' . $token['available_label'] . ')';
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
                    $token['option_label'] = ($token['code'] ?? $token['label'] ?? $key) . ' (∞)';
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
            'amount' => '',
            'price' => '',
        ];
    }

    /**
     * @param array<string, array> $tokens
     */
    private function applyNewOrderGetParams(array $values, array $tokens): array
    {
        $sell = $this->resolveTokenParam($_GET['sell'] ?? null, $tokens, true);
        if ($sell !== null) {
            $values['selling'] = $sell;
        }

        $buy = $this->resolveTokenParam($_GET['buy'] ?? null, $tokens, false);
        if ($buy !== null) {
            $values['buying'] = $buy;
        }

        foreach (['amount', 'price'] as $field) {
            if (isset($_GET[$field]) && is_scalar($_GET[$field])) {
                $values[$field] = trim((string) $_GET[$field]);
            }
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
     * @param array<string, array> $tokens
     */
    private function prepareNewOrder(?AccountResponse $Account, array $tokens, array $values, array &$errors): ?array
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

        $amount_raw = trim((string) ($values['amount'] ?? ''));
        $price_raw = trim((string) ($values['price'] ?? ''));
        $amount = $this->normalizePostedDecimal($amount_raw);
        $price = $this->normalizePostedDecimal($price_raw);
        if ($amount_raw !== '' && ($amount === null || bccomp($amount, '0', 7) <= 0)) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.invalid_amount');
        }
        if ($price_raw !== '' && ($price === null || bccomp($price, '0', 7) <= 0)) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.invalid_price');
        }

        if ($Selling !== null && $amount !== null && isset($Selling['available']) && bccomp($amount, $Selling['available'], 7) > 0) {
            $errors[] = $this->Translator->trans('tools_orders_new.errors.amount_too_big', [
                '%available%' => $Selling['available_label'],
                '%asset%' => $Selling['code'] ?? $Selling['label'] ?? '',
            ]);
        }

        if (
            $errors
            || $Selling === null
            || $Buying === null
            || $amount === null
            || $price === null
            || bccomp($amount, '0', 7) <= 0
            || bccomp($price, '0', 7) <= 0
        ) {
            return null;
        }

        $total = $this->stellarDecimal(bcmul($amount, $price, 7));
        $selling_summary = $this->summaryToken($Selling, $amount);
        $buying_summary = $this->summaryToken($Buying, $total);

        return [
            'selling' => $Selling,
            'buying' => $Buying,
            'amount' => $amount,
            'price' => $price,
            'total' => $total,
            'reverse_price' => $this->reversePrice($price),
            'preview' => [
                'selling' => $selling_summary,
                'buying' => $buying_summary,
                'price' => $this->shortDecimal($price),
                'reverse_price' => $this->reversePrice($price),
            ],
        ];
    }

    private function buildNewOrderSigningForm(AccountResponse $Account, array $prepared, array &$errors): ?string
    {
        try {
            $Transaction = new TransactionBuilder($Account);
            $Transaction->setMaxOperationFee(10000);
            $Transaction->addMemo(Memo::text('New order'));
            $Transaction->addOperation(
                (new ManageSellOfferOperationBuilder(
                    $prepared['selling']['asset'],
                    $prepared['buying']['asset'],
                    $prepared['amount'],
                    $prepared['price']
                ))->setOfferId(0)->build()
            );
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
