<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class AssetSwapController
{
    private const ACTION_BUILD = 'build';
    private const DESCRIPTION = 'Private Asset Swap';

    public function __construct(
        private readonly BSN $BSN,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentContacts $CurrentContacts,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly Translator $Translator,
        private readonly Container $Container,
        private readonly StellarAccountReserveCalculator $ReserveCalculator,
    ) {
    }

    public function AssetSwap(): string
    {
        $has_query = count($_GET) > 0;
        $side_a = strtoupper(trim((string) ($_GET['side_a'] ?? '')));
        if ($side_a === '' && !$has_query) {
            $side_a = $this->CurrentUser->getCurrentAccountId() ?? '';
        }
        $side_b = strtoupper(trim((string) ($_GET['side_b'] ?? '')));
        $action = (string) ($_GET['action'] ?? '');
        $build_requested = $action === self::ACTION_BUILD;
        $selected = [
            'a_asset' => (string) ($_GET['side_a_asset'] ?? ''),
            'b_asset' => (string) ($_GET['side_b_asset'] ?? ''),
            'a_amount' => trim((string) ($_GET['side_a_amount'] ?? '')),
            'b_amount' => trim((string) ($_GET['side_b_amount'] ?? '')),
            'memo' => trim((string) ($_GET['memo'] ?? '')),
        ];

        $errors = [];
        $signing_form = null;
        $side_a_state = $this->buildSideState(
            'a',
            $side_a,
            (!$has_query && $side_a !== '') || array_key_exists('side_a', $_GET) || $build_requested,
            $errors
        );
        $side_b_state = $this->buildSideState('b', $side_b, array_key_exists('side_b', $_GET) || $build_requested, $errors);

        if ($build_requested) {
            if ($side_a === '') {
                $errors[] = $this->Translator->trans('tools_asset_swap.errors.side_a_required');
            }
            if ($side_b === '') {
                $errors[] = $this->Translator->trans('tools_asset_swap.errors.side_b_required');
            }

            if (!$errors && $side_a_state['account_response'] && $side_b_state['account_response']) {
                $signing_form = $this->buildSigningForm($side_a_state, $side_b_state, $selected, $errors);
            }
        }

        return $this->Twig->render('tools_asset_swap.twig', [
            'side_a' => $side_a,
            'side_b' => $side_b,
            'side_a_state' => $side_a_state,
            'side_b_state' => $side_b_state,
            'selected' => $selected,
            'errors' => $errors,
            'signing_form' => $signing_form,
        ]);
    }

    private function buildSideState(string $side, string $account_id, bool $should_load, array &$errors): array
    {
        $state = [
            'side' => $side,
            'account_id' => $account_id,
            'account' => null,
            'account_response' => null,
            'tokens' => [],
            'loaded' => false,
        ];

        if ($account_id === '' || !$should_load) {
            return $state;
        }

        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.invalid_account', [
                '%side%' => $this->sideLabel($side),
            ]);
            return $state;
        }

        try {
            $Account = $this->Stellar->requestAccount($account_id);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.account_not_found', [
                '%side%' => $this->sideLabel($side),
            ]);
            return $state;
        }

        $state['account'] = $this->CurrentContacts->serialize($this->BSN->makeAccountById($account_id));
        $state['account_response'] = $Account;
        $state['tokens'] = $this->buildTokenRows($Account);
        $state['loaded'] = true;

        return $state;
    }

    private function buildTokenRows(AccountResponse $Account): array
    {
        $tokens = [];
        foreach ($Account->getBalances() as $Balance) {
            $key = $this->balanceAssetKey($Balance);
            if ($key === null || bccomp($Balance->getBalance(), '0', 7) <= 0) {
                continue;
            }
            if ($Balance->getIsAuthorized() === false) {
                continue;
            }

            $available = bcsub($Balance->getBalance(), $Balance->getSellingLiabilities() ?? '0.0000000', 7);
            if (bccomp($available, '0', 7) <= 0) {
                continue;
            }

            $issuer = (string) $Balance->getAssetIssuer();
            $code = (string) $Balance->getAssetCode();
            $tokens[$key] = [
                'key' => $key,
                'code' => $code,
                'issuer' => $issuer,
                'issuer_account' => $this->CurrentContacts->serialize($this->BSN->makeAccountById($issuer)),
                'url' => '/tokens/' . rawurlencode($key),
                'balance' => $Balance->getBalance(),
                'available' => $available,
                'has_locked_balance' => bccomp($available, $Balance->getBalance(), 7) !== 0,
                'selling_liabilities' => $Balance->getSellingLiabilities() ?? '0.0000000',
                'balance_response' => $Balance,
            ];
        }

        uasort($tokens, static function (array $a, array $b): int {
            return strcasecmp($a['code'] . '-' . $a['issuer'], $b['code'] . '-' . $b['issuer']);
        });

        return $tokens;
    }

    private function buildSigningForm(array $side_a_state, array $side_b_state, array $selected, array &$errors): ?string
    {
        /** @var AccountResponse $SideAAccount */
        $SideAAccount = $side_a_state['account_response'];
        /** @var AccountResponse $SideBAccount */
        $SideBAccount = $side_b_state['account_response'];
        $side_a = (string) $side_a_state['account_id'];
        $side_b = (string) $side_b_state['account_id'];

        if ($side_a === $side_b) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.same_accounts');
            return null;
        }

        $a_token = $this->selectedToken($side_a_state, $selected['a_asset'], 'a', $errors);
        $b_token = $this->selectedToken($side_b_state, $selected['b_asset'], 'b', $errors);
        $a_amount = $this->normalizeAmount($selected['a_amount'], 'a', $errors);
        $b_amount = $this->normalizeAmount($selected['b_amount'], 'b', $errors);
        $Memo = $this->buildMemo($selected['memo'], $errors);

        if (!$a_token || !$b_token || $a_amount === null || $b_amount === null || $Memo === null) {
            return null;
        }

        $this->validateSendAmount($a_token, $a_amount, 'a', $errors);
        $this->validateSendAmount($b_token, $b_amount, 'b', $errors);
        $a_receives = $this->analyzeReceiver($SideAAccount, $side_a, $b_token, $b_amount, 'a', $errors);
        $b_receives = $this->analyzeReceiver($SideBAccount, $side_b, $a_token, $a_amount, 'b', $errors);
        $this->validateReserve($SideAAccount, $a_receives['needs_trustline'] ? 1 : 0, 'a', $errors);
        $this->validateReserve($SideBAccount, $b_receives['needs_trustline'] ? 1 : 0, 'b', $errors);

        if ($errors) {
            return null;
        }

        $operations = $this->buildOperations($side_a, $side_b, $a_token, $a_amount, $b_token, $b_amount, $a_receives, $b_receives);
        $Transaction = new TransactionBuilder($SideAAccount);
        $Transaction->setMaxOperationFee(10000);
        $Transaction->addMemo($Memo);
        $Transaction->addOperations($operations);
        $xdr = $Transaction->build()->toEnvelopeXdrBase64();

        return $this->Container->get(SignController::class)->SignTransaction($xdr, null, self::DESCRIPTION);
    }

    private function selectedToken(array $side_state, string $key, string $side, array &$errors): ?array
    {
        if ($key === '' || !isset($side_state['tokens'][$key])) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.asset_required', [
                '%side%' => $this->sideLabel($side),
            ]);
            return null;
        }

        return $side_state['tokens'][$key];
    }

    private function normalizeAmount(string $amount, string $side, array &$errors): ?string
    {
        $amount = str_replace(',', '.', trim($amount));
        if (!preg_match('/\A(?:0|[1-9]\d*)(?:\.\d{1,7})?\Z/', $amount)) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.invalid_amount', [
                '%side%' => $this->sideLabel($side),
            ]);
            return null;
        }
        if (bccomp($amount, '0', 7) <= 0) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.amount_positive', [
                '%side%' => $this->sideLabel($side),
            ]);
            return null;
        }

        return bcadd($amount, '0', 7);
    }

    private function buildMemo(string $memo, array &$errors): ?Memo
    {
        if ($memo === '') {
            return Memo::none();
        }
        if (preg_match('/\A[a-f0-9]{64}\Z/i', $memo) === 1) {
            $hash_bytes = hex2bin($memo);
            if ($hash_bytes !== false) {
                return Memo::hash($hash_bytes);
            }
        }
        if (strlen($memo) > 28) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.memo_too_long');
            return null;
        }

        return Memo::text($memo);
    }

    private function validateSendAmount(array $token, string $amount, string $side, array &$errors): void
    {
        if (bccomp($amount, $token['available'], 7) > 0) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.amount_exceeds_available', [
                '%side%' => $this->sideLabel($side),
                '%asset%' => $token['code'],
                '%available%' => $this->shortAmount($token['available']),
            ]);
        }
    }

    private function analyzeReceiver(AccountResponse $Account, string $account_id, array $token, string $amount, string $side, array &$errors): array
    {
        if ($account_id === $token['issuer']) {
            return ['needs_trustline' => false];
        }

        $ReceiverBalance = $this->findBalance($Account, $token['key']);
        if ($ReceiverBalance === null) {
            if ($this->issuerRequiresAuthorization($token['issuer'])) {
                $errors[] = $this->Translator->trans('tools_asset_swap.errors.receiver_needs_issuer_approval', [
                    '%side%' => $this->sideLabel($side),
                    '%asset%' => $token['code'],
                ]);
            }
            return ['needs_trustline' => true];
        }

        if ($ReceiverBalance->getIsAuthorized() === false) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.receiver_not_authorized', [
                '%side%' => $this->sideLabel($side),
                '%asset%' => $token['code'],
            ]);
        }

        $limit = $ReceiverBalance->getLimit();
        if ($limit !== null) {
            $committed = bcadd($ReceiverBalance->getBalance(), $ReceiverBalance->getBuyingLiabilities() ?? '0.0000000', 7);
            $after = bcadd($committed, $amount, 7);
            if (bccomp($after, $limit, 7) > 0) {
                $errors[] = $this->Translator->trans('tools_asset_swap.errors.receiver_limit', [
                    '%side%' => $this->sideLabel($side),
                    '%asset%' => $token['code'],
                ]);
            }
        }

        return ['needs_trustline' => false];
    }

    private function validateReserve(AccountResponse $Account, int $new_entries, string $side, array &$errors): void
    {
        if ($new_entries <= 0) {
            return;
        }

        try {
            $base_reserve = $this->ReserveCalculator->fetchBaseReserveXlm();
            $available = $this->ReserveCalculator->calculateAvailableXlm($Account, $base_reserve);
            $required = $this->ReserveCalculator->requiredReserveForNewEntries($new_entries, $base_reserve);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.reserve_not_checked', [
                '%side%' => $this->sideLabel($side),
            ]);
            return;
        }

        if (bccomp($required, $available, 7) > 0) {
            $errors[] = $this->Translator->trans('tools_asset_swap.errors.reserve_missing', [
                '%side%' => $this->sideLabel($side),
                '%required%' => $this->formatXlmShort($required),
                '%available%' => $this->formatXlmShort($available),
            ]);
        }
    }

    /**
     * @return list<AbstractOperation>
     */
    private function buildOperations(
        string $side_a,
        string $side_b,
        array $a_token,
        string $a_amount,
        array $b_token,
        string $b_amount,
        array $a_receives,
        array $b_receives,
    ): array {
        $operations = [];
        if ($a_receives['needs_trustline']) {
            $operations[] = (new ChangeTrustOperationBuilder($this->assetFromToken($b_token)))->build();
        }
        if ($b_receives['needs_trustline']) {
            $operations[] = (new ChangeTrustOperationBuilder($this->assetFromToken($a_token)))
                ->setSourceAccount($side_b)
                ->build();
        }
        $operations[] = (new PaymentOperationBuilder($side_b, $this->assetFromToken($a_token), $a_amount))->build();
        $operations[] = (new PaymentOperationBuilder($side_a, $this->assetFromToken($b_token), $b_amount))
            ->setSourceAccount($side_b)
            ->build();

        return $operations;
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

    private function findBalance(AccountResponse $Account, string $key): ?AccountBalanceResponse
    {
        foreach ($Account->getBalances() as $Balance) {
            if ($this->balanceAssetKey($Balance) === $key) {
                return $Balance;
            }
        }

        return null;
    }

    private function assetFromToken(array $token): Asset
    {
        return Asset::createNonNativeAsset($token['code'], $token['issuer']);
    }

    private function issuerRequiresAuthorization(string $issuer): bool
    {
        try {
            return $this->Stellar->requestAccount($issuer)->getFlags()->isAuthRequired();
        } catch (\Throwable) {
            return true;
        }
    }

    private function sideLabel(string $side): string
    {
        return $this->Translator->trans('tools_asset_swap.side.' . $side);
    }

    private function shortAmount(string $amount): string
    {
        return (string) (float) $amount;
    }

    private function formatXlmShort(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
