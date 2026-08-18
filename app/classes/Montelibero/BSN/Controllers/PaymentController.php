<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\PaymentDestination;
use Montelibero\BSN\PaymentMemo;
use Montelibero\BSN\PaymentTransactionBuilder;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Montelibero\BSN\StellarTomlImageManager;
use Montelibero\BSN\TokenLabelFormatter;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class PaymentController
{
    private const SCALE = 7;
    private const ANONYMOUS_DESTINATION_LOOKUP_LIMIT = 20;

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
        private readonly PaymentTransactionBuilder $TransactionBuilder,
        private readonly Container $Container,
    ) {
    }

    public function Payment(): ?string
    {
        $source_account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$source_account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tools/payment'),
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
        $tokens = [];

        try {
            $SourceAccount = $this->Stellar->requestAccount($source_account_id);
        } catch (\Throwable) {
            $errors[] = $this->Translator->trans('tools_payment.errors.source_not_found');
        }

        if ($SourceAccount !== null) {
            try {
                $tokens = $this->buildTokenOptions($SourceAccount);
            } catch (\Throwable) {
                $errors[] = $this->Translator->trans('tools_payment.errors.assets_not_loaded');
            }
        }

        $is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $action = $is_post ? $this->scalarString($_POST['action'] ?? '') : '';
        $memo_value = $this->requestMemoValue($is_post);
        $payments = $is_post
            ? $this->postedPaymentRows($action === 'build', $errors)
            : [$this->initialPaymentRow($tokens)];

        if ($payments === []) {
            $payments[] = $this->defaultPaymentRow($tokens);
        }

        if ($is_post) {
            if (($this->scalarString($_POST['form_complete'] ?? '')) !== '1') {
                $errors[] = $this->Translator->trans('tools_payment.errors.form_truncated');
            } elseif (isset($_POST['remove'])) {
                $remove = filter_var($this->scalarString($_POST['remove']), FILTER_VALIDATE_INT);
                if ($remove !== false && isset($payments[$remove])) {
                    array_splice($payments, $remove, 1);
                }
                if ($payments === []) {
                    $payments[] = $this->defaultPaymentRow($tokens);
                }
            } elseif ($action === 'add') {
                if (count($payments) >= PaymentTransactionBuilder::MAX_OPERATIONS) {
                    $errors[] = $this->Translator->trans('tools_payment.errors.too_many_operations');
                } else {
                    $payments[] = $this->defaultPaymentRow($tokens);
                }
            } elseif ($action === 'build' && $SourceAccount !== null && $tokens !== []) {
                $Memo = $this->resolveMemo($memo_value, $errors);
                $prepared = $this->preparePayments(
                    $SourceAccount,
                    $tokens,
                    $payments,
                    $row_errors,
                    $errors,
                );

                if ($Memo !== null && $prepared !== null && $errors === [] && $row_errors === []) {
                    try {
                        $xdr = $this->TransactionBuilder->build($SourceAccount, $prepared['operations'], $Memo->memo);
                        $preview = [
                            'payments' => $prepared['preview'],
                            'memo' => ['type' => $Memo->type, 'value' => $Memo->value],
                            'operation_count' => count($prepared['operations']),
                            'max_fee' => $this->shortDecimal(
                                PaymentTransactionBuilder::maxFeeXlm(count($prepared['operations'])),
                            ),
                        ];
                        $signing_form = $this->Container->get(SignController::class)->SignTransaction(
                            $xdr,
                            null,
                            $this->Translator->trans('tools_payment.signing.description', [
                                '%account%' => $source_account_id,
                            ]),
                            $this->Translator->trans('tools_payment.signing.title'),
                        );
                    } catch (\Throwable) {
                        $errors[] = $this->Translator->trans('tools_payment.errors.transaction_failed');
                    }
                }
            }
        }

        $display_tokens = $this->withTransactionMaximums($tokens, count($payments));

        return $this->Twig->render('tools_payment.twig', [
            'account' => $this->CurrentContacts->serialize($this->BSN->makeAccountById($source_account_id)),
            'source_account_id' => $source_account_id,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'tokens' => $display_tokens,
            'payments' => $payments,
            'memo_value' => $memo_value,
            'max_operations' => PaymentTransactionBuilder::MAX_OPERATIONS,
            'row_errors' => $row_errors,
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
                $available = $this->ReserveCalculator->calculateAvailableXlm($Account);
            } else {
                $available = bcsub(
                    $Balance->getBalance(),
                    $Balance->getSellingLiabilities() ?? '0.0000000',
                    self::SCALE,
                );
            }
            if (bccomp($available, '0', self::SCALE) < 0) {
                $available = '0.0000000';
            }
            $self_receive_capacity = null;
            if ($Balance->getAssetType() !== Asset::TYPE_NATIVE && $Balance->getLimit() !== null) {
                $self_receive_capacity = bcsub(
                    $Balance->getLimit(),
                    bcadd(
                        $Balance->getBalance(),
                        $Balance->getBuyingLiabilities() ?? '0.0000000',
                        self::SCALE,
                    ),
                    self::SCALE,
                );
                if (bccomp($self_receive_capacity, '0', self::SCALE) < 0) {
                    $self_receive_capacity = '0.0000000';
                }
            }

            $token = $this->assetView($Asset);
            $key = $this->assetKey($token);
            $token += [
                'key' => $key,
                'asset' => $Asset,
                'balance' => $this->decimal($Balance->getBalance()),
                'available' => $this->decimal($available),
                'available_label' => $this->shortDecimal($available),
                'available_unlimited' => false,
                'self_receive_capacity' => $self_receive_capacity === null
                    ? null
                    : $this->decimal($self_receive_capacity),
                'disabled' => bccomp($available, '0', self::SCALE) <= 0,
                'is_native' => $Balance->getAssetType() === Asset::TYPE_NATIVE,
                'frozen' => $Balance->getAssetType() === Asset::TYPE_NATIVE
                    ? $this->nonNegativeDifference($Balance->getBalance(), $available)
                    : $this->decimal($Balance->getSellingLiabilities() ?? '0.0000000'),
                'frozen_kind' => $Balance->getAssetType() === Asset::TYPE_NATIVE ? 'account' : 'orders',
            ];
            $token['frozen_label'] = $this->shortDecimal($token['frozen']);
            $token['option_label'] = $this->tokenOptionLabel($token);
            $tokens[$key] = $token;
        }

        foreach ($this->buildIssuedTokenOptions($Account->getAccountId(), $tokens) as $key => $token) {
            $tokens[$key] = $token;
        }

        uasort($tokens, static function (array $a, array $b): int {
            $a_native = ($a['asset'] ?? null) instanceof Asset && $a['asset']->getType() === Asset::TYPE_NATIVE;
            $b_native = ($b['asset'] ?? null) instanceof Asset && $b['asset']->getType() === Asset::TYPE_NATIVE;
            if ($a_native !== $b_native) {
                return $a_native ? -1 : 1;
            }

            return strcasecmp(
                ($a['code'] ?? $a['label'] ?? '') . '-' . ($a['issuer'] ?? ''),
                ($b['code'] ?? $b['label'] ?? '') . '-' . ($b['issuer'] ?? ''),
            );
        });

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
                        'balance' => null,
                        'available' => null,
                        'available_label' => '∞',
                        'available_unlimited' => true,
                        'self_receive_capacity' => null,
                        'disabled' => false,
                        'is_native' => false,
                        'is_issued_by_current_account' => true,
                        'frozen' => '0.0000000',
                        'frozen_label' => '0',
                        'frozen_kind' => 'orders',
                    ];
                    $token['option_label'] = $this->tokenOptionLabel($token);
                    $tokens[$key] = $token;
                }
                $page = $page->getNextPage();
            } while ($page !== null && $page->getAssets()->count() > 0);
        } catch (\Throwable) {
            return [];
        }

        return $tokens;
    }

    /**
     * @return list<array{destination: string, asset: string, amount: string, invalid: bool}>
     */
    private function postedPaymentRows(bool $report_structure_errors, array &$errors): array
    {
        $posted = $_POST['payments'] ?? null;
        if (!is_array($posted)) {
            if ($report_structure_errors) {
                $errors[] = $this->Translator->trans('tools_payment.errors.no_operations');
            }
            return [];
        }

        if (count($posted) > PaymentTransactionBuilder::MAX_OPERATIONS) {
            $errors[] = $this->Translator->trans('tools_payment.errors.too_many_operations');
            $posted = array_slice($posted, 0, PaymentTransactionBuilder::MAX_OPERATIONS, true);
        }

        $rows = [];
        foreach ($posted as $row) {
            $invalid = !is_array($row);
            $row = is_array($row) ? $row : [];
            $rows[] = [
                'destination' => strtoupper(trim($this->scalarString($row['destination'] ?? ''))),
                'asset' => $this->scalarString($row['asset'] ?? ''),
                'amount' => trim($this->scalarString($row['amount'] ?? '')),
                'invalid' => $invalid,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array> $tokens
     */
    private function initialPaymentRow(array $tokens): array
    {
        $row = $this->defaultPaymentRow($tokens);
        $row['destination'] = strtoupper(trim($this->scalarString($_GET['destination'] ?? $_GET['to'] ?? '')));
        $row['amount'] = trim($this->scalarString($_GET['amount'] ?? ''));

        $asset = $this->resolveAssetParam($_GET['asset'] ?? null, $tokens);
        if ($asset !== null) {
            $row['asset'] = $asset;
        }

        return $row;
    }

    /**
     * @param array<string, array> $tokens
     * @return array{destination: string, asset: string, amount: string, invalid: bool}
     */
    private function defaultPaymentRow(array $tokens): array
    {
        $asset = '';
        foreach ($tokens as $key => $token) {
            if (!($token['disabled'] ?? false)) {
                $asset = $key;
                break;
            }
        }
        if ($asset === '' && $tokens !== []) {
            $asset = (string) array_key_first($tokens);
        }

        return [
            'destination' => '',
            'asset' => $asset,
            'amount' => '',
            'invalid' => false,
        ];
    }

    /**
     * @param array<string, array> $tokens
     */
    private function resolveAssetParam(mixed $value, array $tokens): ?string
    {
        $value = trim($this->scalarString($value));
        if ($value === '') {
            return null;
        }
        if (isset($tokens[$value])) {
            return $value;
        }

        foreach ($tokens as $key => $token) {
            if (isset($token['code']) && strcasecmp((string) $token['code'], $value) === 0) {
                return $key;
            }
        }

        return null;
    }

    private function requestMemoValue(bool $is_post): string
    {
        $source = $is_post ? $_POST : $_GET;
        $value_key = $is_post ? 'memo_value' : 'memo';
        return $this->scalarString($source[$value_key] ?? '');
    }

    private function resolveMemo(string $value, array &$errors): ?PaymentMemo
    {
        try {
            return PaymentMemo::fromInput($value);
        } catch (\InvalidArgumentException) {
            $errors[] = $this->Translator->trans('tools_payment.errors.invalid_memo');
            return null;
        }
    }

    /**
     * @param array<string, array> $tokens
     * @return array<string, array>
     */
    private function withTransactionMaximums(array $tokens, int $operation_count): array
    {
        $fee = PaymentTransactionBuilder::maxFeeXlm(min(
            PaymentTransactionBuilder::MAX_OPERATIONS,
            max(0, $operation_count),
        ));

        foreach ($tokens as &$token) {
            if ($token['available_unlimited'] ?? false) {
                $token['transaction_available'] = null;
                $token['transaction_available_label'] = '∞';
                continue;
            }

            $available = (string) ($token['available'] ?? '0.0000000');
            if (($token['asset'] ?? null) instanceof Asset && $token['asset']->getType() === Asset::TYPE_NATIVE) {
                $available = bcsub($available, $fee, self::SCALE);
                if (bccomp($available, '0', self::SCALE) < 0) {
                    $available = '0.0000000';
                }
            }
            $token['transaction_available'] = $this->decimal($available);
            $token['transaction_available_label'] = $this->shortDecimal($available);
        }
        unset($token);

        return $tokens;
    }

    /**
     * @param array<string, array> $tokens
     * @param list<array{destination: string, asset: string, amount: string, invalid: bool}> $payments
     * @return array{operations: list<array{destination: PaymentDestination, asset: Asset, amount: string}>, preview: list<array>}|null
     */
    private function preparePayments(
        AccountResponse $SourceAccount,
        array $tokens,
        array $payments,
        array &$row_errors,
        array &$errors,
    ): ?array {
        if ($payments === []) {
            $errors[] = $this->Translator->trans('tools_payment.errors.no_operations');
            return null;
        }
        if (count($payments) > PaymentTransactionBuilder::MAX_OPERATIONS) {
            $errors[] = $this->Translator->trans('tools_payment.errors.too_many_operations');
            return null;
        }
        if (!$this->CurrentUser->isAuthorized()) {
            $unique_destinations = [];
            foreach ($payments as $payment) {
                try {
                    $Destination = PaymentDestination::fromAddress($payment['destination']);
                } catch (\Throwable) {
                    continue;
                }
                if ($Destination->account_id !== $SourceAccount->getAccountId()) {
                    $unique_destinations[$Destination->account_id] = true;
                }
            }
            if (count($unique_destinations) > self::ANONYMOUS_DESTINATION_LOOKUP_LIMIT) {
                $errors[] = $this->Translator->trans('tools_payment.errors.anonymous_destination_limit', [
                    '%maximum%' => self::ANONYMOUS_DESTINATION_LOOKUP_LIMIT,
                ]);
                return null;
            }
        }

        /** @var array<string, AccountResponse|null> $destination_accounts */
        $destination_accounts = [];
        $prepared = [];

        foreach ($payments as $index => $payment) {
            if ($payment['invalid']) {
                $this->addRowError($row_errors, $index, 'tools_payment.errors.invalid_row');
            }

            $Destination = null;
            try {
                $Destination = PaymentDestination::fromAddress($payment['destination']);
            } catch (\Throwable) {
                $this->addRowError($row_errors, $index, 'tools_payment.errors.destination_invalid');
            }

            $token = $tokens[$payment['asset']] ?? null;
            if ($token === null || !($token['asset'] ?? null) instanceof Asset) {
                $this->addRowError($row_errors, $index, 'tools_payment.errors.invalid_asset');
            }

            $amount = PaymentTransactionBuilder::normalizeAmount($payment['amount']);
            if ($amount === null) {
                $this->addRowError($row_errors, $index, 'tools_payment.errors.invalid_amount');
            }

            $DestinationAccount = null;
            if ($Destination !== null) {
                $destination_id = $Destination->account_id;
                if (!array_key_exists($destination_id, $destination_accounts)) {
                    if ($destination_id === $SourceAccount->getAccountId()) {
                        $destination_accounts[$destination_id] = $SourceAccount;
                    } else {
                        try {
                            $destination_accounts[$destination_id] = $this->Stellar->requestAccount($destination_id);
                        } catch (\Throwable) {
                            $destination_accounts[$destination_id] = null;
                        }
                    }
                }
                $DestinationAccount = $destination_accounts[$destination_id];
                if (!$DestinationAccount instanceof AccountResponse) {
                    $this->addRowError($row_errors, $index, 'tools_payment.errors.destination_not_found');
                }
            }

            if (
                $Destination instanceof PaymentDestination
                && $DestinationAccount instanceof AccountResponse
                && $token !== null
                && ($token['asset'] ?? null) instanceof Asset
                && $amount !== null
            ) {
                $prepared[] = [
                    'index' => $index,
                    'destination' => $Destination,
                    'destination_account' => $DestinationAccount,
                    'token' => $token,
                    'asset' => $token['asset'],
                    'asset_key' => $payment['asset'],
                    'amount' => $amount,
                ];
            }
        }

        $this->validateSourceBalances($SourceAccount->getAccountId(), $tokens, $prepared, $row_errors, $errors);
        $this->validateDestinationBalances($SourceAccount, $prepared, $row_errors);

        if ($errors !== [] || $row_errors !== [] || count($prepared) !== count($payments)) {
            return null;
        }

        $operations = [];
        $preview = [];
        foreach ($prepared as $payment) {
            /** @var PaymentDestination $Destination */
            $Destination = $payment['destination'];
            /** @var Asset $Asset */
            $Asset = $payment['asset'];
            $operations[] = [
                'destination' => $Destination,
                'asset' => $Asset,
                'amount' => $payment['amount'],
            ];

            $destination_view = $this->CurrentContacts->serialize(
                $this->BSN->makeAccountById($Destination->account_id),
            );
            $preview[] = [
                'destination' => $Destination->address,
                'destination_account_id' => $Destination->account_id,
                'is_muxed' => $Destination->isMuxed(),
                'operation' => [
                    'data' => [
                        'to' => $destination_view,
                        'amount' => $payment['amount'],
                        'asset' => $payment['token'],
                    ],
                ],
            ];
        }

        return ['operations' => $operations, 'preview' => $preview];
    }

    /**
     * @param array<string, array> $tokens
     * @param list<array> $payments
     */
    private function validateSourceBalances(
        string $source_account_id,
        array $tokens,
        array $payments,
        array &$row_errors,
        array &$errors,
    ): void {
        $remaining = [];
        foreach ($tokens as $key => $token) {
            if ($token['available_unlimited'] ?? false) {
                continue;
            }
            $remaining[$key] = (string) ($token['available'] ?? '0.0000000');
        }

        $fee = PaymentTransactionBuilder::maxFeeXlm(count($payments));
        if (isset($remaining['XLM'])) {
            $available_before_fee = $remaining['XLM'];
            $remaining['XLM'] = bcsub($remaining['XLM'], $fee, self::SCALE);
            if (bccomp($remaining['XLM'], '0', self::SCALE) < 0) {
                $errors[] = $this->Translator->trans('tools_payment.errors.fee_exceeds_available', [
                    '%fee%' => $this->shortDecimal($fee),
                    '%available%' => $this->shortDecimal($available_before_fee),
                ]);
                $remaining['XLM'] = '0.0000000';
            }
        }

        foreach ($payments as $payment) {
            $key = (string) $payment['asset_key'];
            $token = $payment['token'];
            if ($token['available_unlimited'] ?? false) {
                continue;
            }

            /** @var PaymentDestination $Destination */
            $Destination = $payment['destination'];
            if ($Destination->account_id === $source_account_id) {
                continue;
            }

            $available = $remaining[$key] ?? '0.0000000';
            if (bccomp($payment['amount'], $available, self::SCALE) > 0) {
                $this->addRowError(
                    $row_errors,
                    (int) $payment['index'],
                    'tools_payment.errors.amount_exceeds_available',
                    [
                        '%asset%' => (string) ($token['code'] ?? $token['label'] ?? $key),
                        '%available%' => $this->shortDecimal($available),
                    ],
                );
                continue;
            }

            $remaining[$key] = bcsub($available, $payment['amount'], self::SCALE);
        }
    }

    /**
     * @param list<array> $payments
     */
    private function validateDestinationBalances(
        AccountResponse $SourceAccount,
        array $payments,
        array &$row_errors,
    ): void
    {
        $source_account_id = $SourceAccount->getAccountId();
        $capacities = [];
        $source_outgoing = [];
        foreach ($payments as $payment) {
            /** @var PaymentDestination $Destination */
            $Destination = $payment['destination'];
            /** @var Asset $Asset */
            $Asset = $payment['asset'];
            if ($Asset->getType() === Asset::TYPE_NATIVE) {
                continue;
            }
            if (!$Asset instanceof AssetTypeCreditAlphanum) {
                $this->addRowError($row_errors, (int) $payment['index'], 'tools_payment.errors.invalid_asset');
                continue;
            }
            $asset_key = (string) $payment['asset_key'];
            $destination_is_source = $Destination->account_id === $source_account_id;
            $destination_valid = false;

            if ($Destination->account_id === $Asset->getIssuer()) {
                $destination_valid = true;
            } else {
                /** @var AccountResponse $DestinationAccount */
                $DestinationAccount = $payment['destination_account'];
                $Balance = $this->findBalance($DestinationAccount, $asset_key);
                if ($Balance === null) {
                    $this->addRowError(
                        $row_errors,
                        (int) $payment['index'],
                        'tools_payment.errors.trustline_missing',
                        ['%asset%' => $Asset->getCode()],
                    );
                    continue;
                }
                if ($Balance->getIsAuthorized() === false) {
                    $this->addRowError(
                        $row_errors,
                        (int) $payment['index'],
                        'tools_payment.errors.receiver_not_authorized',
                        ['%asset%' => $Asset->getCode()],
                    );
                    continue;
                }

                $capacity_key = $Destination->account_id . '|' . $asset_key;
                if (!array_key_exists($capacity_key, $capacities)) {
                    $limit = $Balance->getLimit();
                    if ($limit === null) {
                        $this->addRowError(
                            $row_errors,
                            (int) $payment['index'],
                            'tools_payment.errors.receiver_limit',
                            ['%asset%' => $Asset->getCode()],
                        );
                        continue;
                    }

                    $committed = bcadd(
                        $Balance->getBalance(),
                        $Balance->getBuyingLiabilities() ?? '0.0000000',
                        self::SCALE,
                    );
                    $capacities[$capacity_key] = bcsub($limit, $committed, self::SCALE);
                    if ($destination_is_source) {
                        $capacities[$capacity_key] = bcadd(
                            $capacities[$capacity_key],
                            $source_outgoing[$asset_key] ?? '0.0000000',
                            self::SCALE,
                        );
                    }
                    if (bccomp($capacities[$capacity_key], '0', self::SCALE) < 0) {
                        $capacities[$capacity_key] = '0.0000000';
                    }
                }

                if (bccomp($payment['amount'], $capacities[$capacity_key], self::SCALE) > 0) {
                    $this->addRowError(
                        $row_errors,
                        (int) $payment['index'],
                        'tools_payment.errors.receiver_limit',
                        ['%asset%' => $Asset->getCode()],
                    );
                    continue;
                }
                $destination_valid = true;
                if (!$destination_is_source) {
                    $capacities[$capacity_key] = bcsub(
                        $capacities[$capacity_key],
                        $payment['amount'],
                        self::SCALE,
                    );
                }
            }

            if (
                $destination_valid
                && !$destination_is_source
                && $source_account_id !== $Asset->getIssuer()
            ) {
                $source_outgoing[$asset_key] = bcadd(
                    $source_outgoing[$asset_key] ?? '0.0000000',
                    $payment['amount'],
                    self::SCALE,
                );
                $source_capacity_key = $source_account_id . '|' . $asset_key;
                if (isset($capacities[$source_capacity_key])) {
                    $capacities[$source_capacity_key] = bcadd(
                        $capacities[$source_capacity_key],
                        $payment['amount'],
                        self::SCALE,
                    );
                }
            }
        }
    }

    private function findBalance(AccountResponse $Account, string $asset_key): ?AccountBalanceResponse
    {
        foreach ($Account->getBalances() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse || $Balance->getAssetType() === Asset::TYPE_NATIVE) {
                continue;
            }
            if ($Balance->getAssetCode() . '-' . $Balance->getAssetIssuer() === $asset_key) {
                return $Balance;
            }
        }

        return null;
    }

    private function addRowError(
        array &$row_errors,
        int $index,
        string $translation_key,
        array $parameters = [],
    ): void {
        $message = $this->Translator->trans($translation_key, $parameters);
        if (!in_array($message, $row_errors[$index] ?? [], true)) {
            $row_errors[$index][] = $message;
        }
    }

    private function scalarString(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    private function tokenOptionLabel(array $token): string
    {
        return $this->TokenLabelFormatter->formatToken($token)
            . ' (' . ($token['available_label'] ?? '0') . ')';
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
            return ['label' => $Asset->getType()];
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

    private function shortDecimal(string $amount): string
    {
        $amount = rtrim(rtrim($this->decimal($amount), '0'), '.');
        return $amount === '' ? '0' : $amount;
    }

    private function decimal(string $amount): string
    {
        $parts = explode('.', $amount, 2);
        $integer = $parts[0] === '' ? '0' : $parts[0];

        return $integer . '.' . str_pad(substr($parts[1] ?? '', 0, self::SCALE), self::SCALE, '0');
    }

    private function nonNegativeDifference(string $amount, string $available): string
    {
        $difference = bcsub($amount, $available, self::SCALE);

        return bccomp($difference, '0', self::SCALE) < 0 ? '0.0000000' : $this->decimal($difference);
    }
}
