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
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PathPaymentStrictReceiveOperationBuilder;
use Soneso\StellarSDK\PathPaymentStrictSendOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\PaymentPath\PathResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class SwapController
{
    private const SCALE = 7;
    private const FIX_SEND = 'send';
    private const FIX_RECEIVE = 'receive';

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

    public function Swap(): ?string
    {
        $source_account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$source_account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tools/swap'),
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
        $SourceAccount = null;
        $DestinationAccount = null;
        $source_tokens = [];
        $destination_tokens = [];
        $values = $this->requestValues($source_account_id);
        $destination_account_id = $values['destination'] !== '' ? $values['destination'] : $source_account_id;

        try {
            $SourceAccount = $this->Stellar->requestAccount($source_account_id);
            $source_tokens = $this->buildSourceTokenOptions($SourceAccount);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_swap.errors.source_not_found');
        }

        if (!BSN::validateStellarAccountIdFormat($destination_account_id)) {
            if ($values['destination'] !== '') {
                $errors[] = $this->Translator->trans('tools_swap.errors.destination_invalid');
            }
        } else {
            try {
                $DestinationAccount = $destination_account_id === $source_account_id && $SourceAccount !== null
                    ? $SourceAccount
                    : $this->Stellar->requestAccount($destination_account_id);
                $destination_tokens = $this->buildDestinationTokenOptions($DestinationAccount);
            } catch (\Throwable) {
                $errors[] = $this->Translator->trans('tools_swap.errors.destination_not_found');
            }
        }

        $values = $this->applyDefaults($values, $source_tokens, $destination_tokens);
        $selected_send = $source_tokens[$values['send_asset']] ?? null;
        $selected_receive = $destination_tokens[$values['receive_asset']] ?? null;

        if ($this->hasEnoughForQuote($values) && $SourceAccount !== null && $DestinationAccount !== null) {
            $prepared = $this->prepareSwap(
                $SourceAccount,
                $destination_account_id,
                $source_tokens,
                $destination_tokens,
                $values,
                $errors
            );
            if ($prepared !== null && !$errors) {
                $preview = $prepared['preview'];
                $signing_form = $this->buildSigningForm($SourceAccount, $destination_account_id, $prepared, $errors);
            }
        }

        return $this->Twig->render('tools_swap.twig', [
            'account' => $this->CurrentContacts->serialize($this->BSN->makeAccountById($source_account_id)),
            'source_account_id' => $source_account_id,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'destination_account_id' => $destination_account_id,
            'source_tokens' => $source_tokens,
            'destination_tokens' => $destination_tokens,
            'selected_send' => $selected_send,
            'selected_receive' => $selected_receive,
            'values' => $values,
            'preview' => $preview,
            'errors' => $errors,
            'signing_form' => $signing_form,
        ]);
    }

    private function requestValues(string $source_account_id): array
    {
        $fix = (string) ($_GET['fix'] ?? self::FIX_SEND);
        if (!in_array($fix, [self::FIX_SEND, self::FIX_RECEIVE], true)) {
            $fix = self::FIX_SEND;
        }

        return [
            'destination' => strtoupper(trim((string) ($_GET['destination'] ?? ''))),
            'send_asset' => (string) ($_GET['send_asset'] ?? $_GET['send'] ?? ''),
            'receive_asset' => (string) ($_GET['receive_asset'] ?? $_GET['receive'] ?? ''),
            'fix' => $fix,
            'amount' => trim((string) ($_GET['amount'] ?? '')),
            'slippage' => trim((string) ($_GET['slippage'] ?? '5')),
            'source_account' => $source_account_id,
        ];
    }

    private function applyDefaults(array $values, array $source_tokens, array $destination_tokens): array
    {
        if ($values['send_asset'] === '' || !isset($source_tokens[$values['send_asset']])) {
            $values['send_asset'] = '';
            foreach ($source_tokens as $key => $token) {
                if (!$token['disabled']) {
                    $values['send_asset'] = $key;
                    break;
                }
            }
        }

        if ($values['receive_asset'] === '' || !isset($destination_tokens[$values['receive_asset']])) {
            $values['receive_asset'] = '';
            foreach ($destination_tokens as $key => $token) {
                if ($key !== $values['send_asset']) {
                    $values['receive_asset'] = $key;
                    break;
                }
            }
        }

        return $values;
    }

    private function hasEnoughForQuote(array $values): bool
    {
        return $values['send_asset'] !== ''
            && $values['receive_asset'] !== ''
            && trim((string) $values['amount']) !== '';
    }

    private function prepareSwap(
        AccountResponse $SourceAccount,
        string $destination_account_id,
        array $source_tokens,
        array $destination_tokens,
        array $values,
        array &$errors,
    ): ?array {
        $send = $source_tokens[$values['send_asset']] ?? null;
        $receive = $destination_tokens[$values['receive_asset']] ?? null;
        if ($send === null) {
            $errors[] = $this->Translator->trans('tools_swap.errors.invalid_send_asset');
        }
        if ($receive === null) {
            $errors[] = $this->Translator->trans('tools_swap.errors.invalid_receive_asset');
        }
        if ($send !== null && $receive !== null && $send['key'] === $receive['key']) {
            $errors[] = $this->Translator->trans('tools_swap.errors.same_assets');
        }

        $amount = $this->normalizeDecimal($values['amount']);
        if ($amount === null || bccomp($amount, '0', self::SCALE) <= 0) {
            $errors[] = $this->Translator->trans('tools_swap.errors.invalid_amount');
        }

        $slippage = $this->normalizePercent($values['slippage']);
        if ($slippage === null) {
            $errors[] = $this->Translator->trans('tools_swap.errors.invalid_slippage');
        }

        if ($errors || $send === null || $receive === null || $amount === null || $slippage === null) {
            return null;
        }

        try {
            $path = $values['fix'] === self::FIX_RECEIVE
                ? $this->bestStrictReceivePath($send['asset'], $receive['asset'], $amount)
                : $this->bestStrictSendPath($send['asset'], $receive['asset'], $amount);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_swap.errors.paths_failed');
            return null;
        }

        if ($path === null) {
            $errors[] = $this->Translator->trans('tools_swap.errors.no_path');
            return null;
        }

        if ($values['fix'] === self::FIX_RECEIVE) {
            $source_amount = $this->decimal($path->getSourceAmount());
            $send_max = $this->addPercentCeil($source_amount, $slippage);
            if (bccomp($send_max, $send['available'], self::SCALE) > 0) {
                $errors[] = $this->Translator->trans('tools_swap.errors.amount_exceeds_available', [
                    '%available%' => $send['available_label'],
                    '%asset%' => $send['code'] ?? '',
                ]);
                return null;
            }
            $this->validateReceiveCapacity($receive, $amount, $errors);

            return [
                'mode' => self::FIX_RECEIVE,
                'send' => $send,
                'receive' => $receive,
                'amount' => $amount,
                'source_amount' => $source_amount,
                'send_max' => $send_max,
                'destination_amount' => $amount,
                'path' => $path->getPath()->toArray(),
                'preview' => [
                    'mode' => self::FIX_RECEIVE,
                    'send' => $this->summaryToken($send, $source_amount),
                    'receive' => $this->summaryToken($receive, $amount),
                    'source_amount' => $this->shortDecimal($source_amount),
                    'send_max' => $this->shortDecimal($send_max),
                    'destination_amount' => $this->shortDecimal($amount),
                ],
            ];
        }

        if (bccomp($amount, $send['available'], self::SCALE) > 0) {
            $errors[] = $this->Translator->trans('tools_swap.errors.amount_exceeds_available', [
                '%available%' => $send['available_label'],
                '%asset%' => $send['code'] ?? '',
            ]);
            return null;
        }
        $destination_amount = $this->decimal($path->getDestinationAmount());
        $dest_min = $this->subtractPercentFloor($destination_amount, $slippage);
        $this->validateReceiveCapacity($receive, $destination_amount, $errors);
        if ($errors) {
            return null;
        }

        return [
            'mode' => self::FIX_SEND,
            'send' => $send,
            'receive' => $receive,
            'amount' => $amount,
            'source_amount' => $amount,
            'destination_amount' => $destination_amount,
            'dest_min' => $dest_min,
            'path' => $path->getPath()->toArray(),
            'preview' => [
                'mode' => self::FIX_SEND,
                'send' => $this->summaryToken($send, $amount),
                'receive' => $this->summaryToken($receive, $destination_amount),
                'source_amount' => $this->shortDecimal($amount),
                'destination_amount' => $this->shortDecimal($destination_amount),
                'dest_min' => $this->shortDecimal($dest_min),
            ],
        ];
    }

    private function buildSigningForm(AccountResponse $SourceAccount, string $destination_account_id, array $prepared, array &$errors): ?string
    {
        try {
            $Transaction = new TransactionBuilder($SourceAccount);
            $Transaction->setMaxOperationFee(10000);
            $Transaction->addMemo(Memo::text('Swap'));

            if ($prepared['mode'] === self::FIX_RECEIVE) {
                $Operation = new PathPaymentStrictReceiveOperationBuilder(
                    $prepared['send']['asset'],
                    $prepared['send_max'],
                    $destination_account_id,
                    $prepared['receive']['asset'],
                    $prepared['destination_amount']
                );
            } else {
                $Operation = new PathPaymentStrictSendOperationBuilder(
                    $prepared['send']['asset'],
                    $prepared['source_amount'],
                    $destination_account_id,
                    $prepared['receive']['asset'],
                    $prepared['dest_min']
                );
            }
            $Operation->setPath($prepared['path']);
            $Transaction->addOperation($Operation->build());
            $xdr = $Transaction->build()->toEnvelopeXdrBase64();
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_swap.errors.transaction_failed');
            return null;
        }

        return $this->Container->get(SignController::class)->SignTransaction(
            $xdr,
            null,
            $this->Translator->trans('tools_swap.signing.description'),
            $this->Translator->trans('tools_swap.signing.title')
        );
    }

    private function bestStrictSendPath(Asset $send_asset, Asset $receive_asset, string $send_amount): ?PathResponse
    {
        $page = $this->Stellar->findStrictSendPaths()
            ->forSourceAsset($send_asset)
            ->forSourceAmount($send_amount)
            ->forDestinationAssets([$receive_asset])
            ->limit(20)
            ->execute();

        $best = null;
        foreach ($page->getPaths() as $path) {
            if ($best === null || bccomp($path->getDestinationAmount(), $best->getDestinationAmount(), self::SCALE) > 0) {
                $best = $path;
            }
        }

        return $best;
    }

    private function bestStrictReceivePath(Asset $send_asset, Asset $receive_asset, string $receive_amount): ?PathResponse
    {
        $page = $this->Stellar->findStrictReceivePaths()
            ->forSourceAssets([$send_asset])
            ->forDestinationAsset($receive_asset)
            ->forDestinationAmount($receive_amount)
            ->limit(20)
            ->execute();

        $best = null;
        foreach ($page->getPaths() as $path) {
            if ($best === null || bccomp($path->getSourceAmount(), $best->getSourceAmount(), self::SCALE) < 0) {
                $best = $path;
            }
        }

        return $best;
    }

    private function buildSourceTokenOptions(AccountResponse $Account): array
    {
        $tokens = [];
        foreach ($Account->getBalances() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse) {
                continue;
            }
            if ($Balance->getAssetType() !== Asset::TYPE_NATIVE && $Balance->getIsAuthorized() === false) {
                continue;
            }

            $Asset = $this->assetFromBalance($Balance);
            if ($Asset === null) {
                continue;
            }
            $available = $this->availableBalance($Account, $Balance, $Asset);
            $token = $this->assetView($Asset);
            $key = $this->assetKey($token);
            $token += [
                'key' => $key,
                'asset' => $Asset,
                'balance' => $this->decimal($Balance->getBalance()),
                'available' => $available,
                'available_label' => $this->shortDecimal($available),
                'disabled' => bccomp($available, '0', self::SCALE) <= 0,
            ];
            $token['option_label'] = ($token['code'] ?? $token['label'] ?? $key) . ' (' . $token['available_label'] . ')';
            $tokens[$key] = $token;
        }

        return $this->sortTokens($tokens);
    }

    private function buildDestinationTokenOptions(AccountResponse $Account): array
    {
        $tokens = [];
        foreach ($Account->getBalances() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse) {
                continue;
            }
            if ($Balance->getAssetType() !== Asset::TYPE_NATIVE && $Balance->getIsAuthorized() === false) {
                continue;
            }

            $Asset = $this->assetFromBalance($Balance);
            if ($Asset === null) {
                continue;
            }
            $token = $this->assetView($Asset);
            $key = $this->assetKey($token);
            $token += [
                'key' => $key,
                'asset' => $Asset,
                'balance' => $this->decimal($Balance->getBalance()),
                'buying_liabilities' => $this->decimal($Balance->getBuyingLiabilities() ?? '0'),
                'limit' => $Balance->getLimit(),
                'option_label' => $token['code'] ?? $token['label'] ?? $key,
            ];
            $tokens[$key] = $token;
        }

        return $this->sortTokens($tokens);
    }

    private function sortTokens(array $tokens): array
    {
        uasort($tokens, static function (array $a, array $b): int {
            return strcasecmp(
                ($a['code'] ?? $a['label'] ?? '') . '-' . ($a['issuer'] ?? ''),
                ($b['code'] ?? $b['label'] ?? '') . '-' . ($b['issuer'] ?? '')
            );
        });

        return $tokens;
    }

    private function availableBalance(AccountResponse $Account, AccountBalanceResponse $Balance, Asset $Asset): string
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            $available = $this->ReserveCalculator->calculateAvailableXlm($Account);
        } else {
            $available = bcsub($Balance->getBalance(), $Balance->getSellingLiabilities() ?? '0.0000000', self::SCALE);
        }

        return bccomp($available, '0', self::SCALE) < 0 ? '0.0000000' : $this->decimal($available);
    }

    private function validateReceiveCapacity(array $receive, string $amount, array &$errors): void
    {
        if (($receive['asset'] ?? null) instanceof Asset && $receive['asset']->getType() === Asset::TYPE_NATIVE) {
            return;
        }

        $limit = $receive['limit'] ?? null;
        if ($limit === null) {
            return;
        }

        $committed = bcadd($receive['balance'] ?? '0', $receive['buying_liabilities'] ?? '0', self::SCALE);
        $after = bcadd($committed, $amount, self::SCALE);
        if (bccomp($after, $limit, self::SCALE) > 0) {
            $errors[] = $this->Translator->trans('tools_swap.errors.receive_limit', [
                '%asset%' => $receive['code'] ?? '',
            ]);
        }
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^\d+(?:\.\d{0,7})?$/', $value)) {
            return null;
        }

        return $this->decimal($value);
    }

    private function normalizePercent(mixed $value): ?string
    {
        $percent = $this->normalizeDecimal($value);
        if ($percent === null || bccomp($percent, '0', self::SCALE) < 0 || bccomp($percent, '100', self::SCALE) > 0) {
            return null;
        }

        return $percent;
    }

    private function addPercentCeil(string $amount, string $percent): string
    {
        $multiplier = bcadd('1', bcdiv($percent, '100', self::SCALE), self::SCALE);
        $value = bcmul($amount, $multiplier, self::SCALE + 1);

        return $this->ceilStellar($value);
    }

    private function subtractPercentFloor(string $amount, string $percent): string
    {
        $multiplier = bcsub('1', bcdiv($percent, '100', self::SCALE), self::SCALE);
        $value = bcmul($amount, $multiplier, self::SCALE + 1);

        return $this->floorStellar($value);
    }

    private function ceilStellar(string $value): string
    {
        $factor = 10 ** self::SCALE;
        return number_format(ceil((float) $value * $factor) / $factor, self::SCALE, '.', '');
    }

    private function floorStellar(string $value): string
    {
        $factor = 10 ** self::SCALE;
        return number_format(floor((float) $value * $factor) / $factor, self::SCALE, '.', '');
    }

    private function summaryToken(array $token, string $amount): array
    {
        $result = $token;
        $result['amount'] = $this->shortDecimal($amount);
        return $result;
    }

    private function shortDecimal(string $amount): string
    {
        $amount = rtrim(rtrim($this->decimal($amount), '0'), '.');
        return $amount === '' ? '0' : $amount;
    }

    private function decimal(string $amount): string
    {
        $parts = explode('.', $amount, 2);
        $int = $parts[0] === '' ? '0' : $parts[0];
        $frac = $parts[1] ?? '';

        return $int . '.' . str_pad(substr($frac, 0, self::SCALE), self::SCALE, '0');
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
