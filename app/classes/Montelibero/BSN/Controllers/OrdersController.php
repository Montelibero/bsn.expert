<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\StellarTomlImageManager;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\ManageSellOfferOperationBuilder;
use Soneso\StellarSDK\Memo;
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
