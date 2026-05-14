<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\ManageSellOfferOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Offers\OfferResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class CloseTrustlinesController
{
    private const MAX_OPERATIONS_PER_TRANSACTION = 100;

    public function __construct(
        private readonly BSN $BSN,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentContacts $CurrentContacts,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly Translator $Translator,
        private readonly Container $Container,
    ) {
    }

    public function CloseTrustlines(): ?string
    {
        $account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tools/close_trustlines'),
                302
            );
            return null;
        }

        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $errors = [];
        $signing_forms = [];
        $zero_tokens = [];
        $nonzero_tokens = [];
        $selected_token_keys = $this->selectedTokenKeys($_POST['tokens'] ?? []);

        $AccountResponse = $this->fetchAccount($account_id, $errors);
        if ($AccountResponse !== null) {
            $offers = $this->loadOffers($account_id, $errors);
            [$zero_tokens, $nonzero_tokens] = $this->buildTokenLists($AccountResponse, $offers);

            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                if (!$selected_token_keys) {
                    $errors[] = $this->Translator->trans('tools_close_trustlines.errors.no_tokens_selected');
                } else {
                    $this->validateSelectedTokens($selected_token_keys, $zero_tokens, $errors);
                }

                if (!$errors) {
                    $signing_forms = $this->buildSigningForms($AccountResponse, $zero_tokens, $selected_token_keys);
                }
            }
        }

        return $this->Twig->render('tools_close_trustlines.twig', [
            'account' => $this->CurrentContacts->serialize($this->BSN->makeAccountById($account_id)),
            'account_id' => $account_id,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'zero_tokens' => $zero_tokens,
            'nonzero_tokens' => $nonzero_tokens,
            'selected_token_keys' => $selected_token_keys,
            'errors' => $errors,
            'signing_forms' => $signing_forms,
        ]);
    }

    private function fetchAccount(string $account_id, array &$errors): ?AccountResponse
    {
        try {
            return $this->Stellar->requestAccount($account_id);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_close_trustlines.errors.account_not_found');
            return null;
        }
    }

    /**
     * @return list<OfferResponse>
     */
    private function loadOffers(string $account_id, array &$errors): array
    {
        $result = [];
        try {
            $page = $this->Stellar->offers()->forAccount($account_id)->limit(200)->execute();
            do {
                foreach ($page->getOffers() as $Offer) {
                    $result[] = $Offer;
                }
                $page = $page->getNextPage();
            } while ($page !== null && $page->getOffers()->count() > 0);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_close_trustlines.errors.offers_not_loaded');
        }

        return $result;
    }

    /**
     * @param list<OfferResponse> $offers
     * @return array{0: array<string, array>, 1: array<string, array>}
     */
    private function buildTokenLists(AccountResponse $Account, array $offers): array
    {
        $zero_tokens = [];
        $nonzero_tokens = [];
        $buying_offers_by_asset = $this->buyingOffersByAsset($offers);

        foreach ($Account->getBalances() as $Balance) {
            $key = $this->balanceAssetKey($Balance);
            if ($key === null) {
                continue;
            }
            $code = $Balance->getAssetCode();
            $issuer = $Balance->getAssetIssuer();
            if ($code === null || $issuer === null) {
                continue;
            }

            $token = [
                'key' => $key,
                'code' => $code,
                'issuer' => $issuer,
                'issuer_account' => $this->CurrentContacts->serialize($this->BSN->makeAccountById($issuer)),
                'url' => '/tokens/' . rawurlencode($key),
                'balance' => $Balance->getBalance(),
                'buying_liabilities' => $Balance->getBuyingLiabilities() ?? '0.0000000',
                'selling_liabilities' => $Balance->getSellingLiabilities() ?? '0.0000000',
                'buying_offers' => $buying_offers_by_asset[$key] ?? [],
            ];

            if (bccomp($Balance->getBalance(), '0', 7) === 0) {
                $zero_tokens[$key] = $token;
            } else {
                $nonzero_tokens[$key] = $token;
            }
        }

        $sort = static function (array $a, array $b): int {
            return strcasecmp($a['code'] . '-' . $a['issuer'], $b['code'] . '-' . $b['issuer']);
        };
        uasort($zero_tokens, $sort);
        uasort($nonzero_tokens, $sort);

        return [$zero_tokens, $nonzero_tokens];
    }

    /**
     * @param list<OfferResponse> $offers
     * @return array<string, list<array>>
     */
    private function buyingOffersByAsset(array $offers): array
    {
        $result = [];
        foreach ($offers as $Offer) {
            $buying_key = $this->assetKey($Offer->getBuying());
            if ($buying_key === null) {
                continue;
            }

            $selling = $this->assetView($Offer->getSelling());
            $buying = $this->assetView($Offer->getBuying());
            $result[$buying_key][] = [
                'id' => $Offer->getOfferId(),
                'selling' => $selling,
                'buying' => $buying,
                'amount' => $Offer->getAmount(),
                'amount_short' => $this->shortAmount($Offer->getAmount()),
                'price' => $Offer->getPrice(),
                'total' => bcmul($Offer->getAmount(), $Offer->getPrice(), 7),
                'total_short' => $this->shortAmount(bcmul($Offer->getAmount(), $Offer->getPrice(), 7)),
                'offer_response' => $Offer,
            ];
        }

        return $result;
    }

    private function shortAmount(string $amount): string
    {
        return (string) (float) $amount;
    }

    private function selectedTokenKeys(mixed $posted_tokens): array
    {
        if (!is_array($posted_tokens)) {
            return [];
        }

        $selected = [];
        foreach ($posted_tokens as $key => $value) {
            if ((string) $value !== '1') {
                continue;
            }
            if (!is_string($key) || !$this->isValidTokenKey($key)) {
                continue;
            }
            $selected[$key] = true;
        }

        return array_keys($selected);
    }

    private function validateSelectedTokens(array $selected_token_keys, array $zero_tokens, array &$errors): void
    {
        foreach ($selected_token_keys as $key) {
            if (!isset($zero_tokens[$key])) {
                $errors[] = $this->Translator->trans('tools_close_trustlines.errors.token_not_zero', [
                    '%asset%' => $key,
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function buildSigningForms(AccountResponse $Account, array $zero_tokens, array $selected_token_keys): array
    {
        $operations = [];
        $closed_offer_ids = [];
        foreach ($selected_token_keys as $key) {
            $token = $zero_tokens[$key] ?? null;
            if ($token === null) {
                continue;
            }
            foreach ($token['buying_offers'] as $offer) {
                $offer_id = (int) $offer['id'];
                if (isset($closed_offer_ids[$offer_id])) {
                    continue;
                }
                $closed_offer_ids[$offer_id] = true;
                $operations[] = (new ManageSellOfferOperationBuilder(
                    $offer['offer_response']->getSelling(),
                    $offer['offer_response']->getBuying(),
                    '0',
                    $offer['price']
                ))->setOfferId($offer_id)->build();
            }
        }

        foreach ($selected_token_keys as $key) {
            $token = $zero_tokens[$key] ?? null;
            if ($token === null) {
                continue;
            }
            $operations[] = (new ChangeTrustOperationBuilder($this->assetFromToken($token), '0'))->build();
        }

        $forms = [];
        $total = (int) ceil(count($operations) / self::MAX_OPERATIONS_PER_TRANSACTION);
        foreach (array_chunk($operations, self::MAX_OPERATIONS_PER_TRANSACTION) as $index => $chunk) {
            $Transaction = new TransactionBuilder($Account);
            $Transaction->setMaxOperationFee(10000);
            $Transaction->addMemo(Memo::text('Close trustlines'));
            $Transaction->addOperations($chunk);
            $xdr = $Transaction->build()->toEnvelopeXdrBase64();
            $description = $this->Translator->trans('tools_close_trustlines.signing.description', [
                '%number%' => (string) ($index + 1),
                '%total%' => (string) $total,
                '%account%' => $Account->getAccountId(),
            ]);
            $forms[] = $this->Container->get(SignController::class)->SignTransaction($xdr, null, $description);
        }

        return $forms;
    }

    private function balanceAssetKey(AccountBalanceResponse $Balance): ?string
    {
        if ($Balance->getAssetType() === Asset::TYPE_NATIVE) {
            return null;
        }

        $code = $Balance->getAssetCode();
        $issuer = $Balance->getAssetIssuer();
        if ($code === null || $issuer === null) {
            return null;
        }

        return $code . '-' . $issuer;
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

    private function assetFromToken(array $token): Asset
    {
        return Asset::createNonNativeAsset($token['code'], $token['issuer']);
    }

    private function isValidTokenKey(string $key): bool
    {
        $parts = explode('-', $key, 2);
        return count($parts) === 2
            && BSN::validateTokenNameFormat($parts[0])
            && BSN::validateStellarAccountIdFormat($parts[1]);
    }
}
