<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use InvalidArgumentException;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\AccountMergeOperation;
use Soneso\StellarSDK\AllowTrustOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\AssetTypeNative;
use Soneso\StellarSDK\BeginSponsoringFutureReservesOperation;
use Soneso\StellarSDK\BumpSequenceOperation;
use Soneso\StellarSDK\ChangeTrustOperation;
use Soneso\StellarSDK\ClaimClaimableBalanceOperation;
use Soneso\StellarSDK\ClawbackClaimableBalanceOperation;
use Soneso\StellarSDK\ClawbackOperation;
use Soneso\StellarSDK\CreateAccountOperation;
use Soneso\StellarSDK\CreatePassiveSellOfferOperation;
use Soneso\StellarSDK\EndSponsoringFutureReservesOperation;
use Soneso\StellarSDK\FeeBumpTransaction;
use Soneso\StellarSDK\LiquidityPoolDepositOperation;
use Soneso\StellarSDK\LiquidityPoolWithdrawOperation;
use Soneso\StellarSDK\ManageBuyOfferOperation;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\ManageSellOfferOperation;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\PathPaymentStrictReceiveOperation;
use Soneso\StellarSDK\PathPaymentStrictSendOperation;
use Soneso\StellarSDK\PaymentOperation;
use Soneso\StellarSDK\Price;
use Soneso\StellarSDK\Transaction;

final class StellarLabUrlGenerator
{
    private const LAB_BASE_URL = 'https://lab.stellar.org';
    private const MAX_TRUSTLINE_LIMIT = '922337203685.4775807';
    private const MAINNET = [
        'id' => 'mainnet',
        'label' => 'Mainnet',
        'horizonUrl' => 'https://horizon.stellar.org',
        'horizonHeaderName' => '',
        'rpcUrl' => 'https://mainnet.sorobanrpc.com',
        'rpcHeaderName' => '',
        'passphrase' => 'Public Global Stellar Network ; September 2015',
    ];

    public function generateMainnetClassicBuildUrl(string $xdr): string
    {
        $normalizedXdr = preg_replace('/\s+/', '', trim($xdr)) ?? '';
        if ($normalizedXdr === '') {
            throw new InvalidArgumentException('XDR is empty.');
        }

        $transaction = AbstractTransaction::fromEnvelopeBase64XdrString($normalizedXdr);
        if ($transaction instanceof FeeBumpTransaction) {
            throw new InvalidArgumentException('Fee-bump transactions are not supported.');
        }
        if (!$transaction instanceof Transaction) {
            throw new InvalidArgumentException('Unsupported transaction envelope.');
        }
        if ($transaction->getSorobanTransactionData() !== null) {
            throw new InvalidArgumentException('Soroban transactions are not supported.');
        }

        $operations = $transaction->getOperations();
        if (count($operations) === 0) {
            throw new InvalidArgumentException('Transaction has no operations.');
        }

        $operationCount = count($operations);
        $totalFee = $transaction->getFee();
        if ($totalFee % $operationCount !== 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Transaction fee %d is not divisible by operation count %d.',
                    $totalFee,
                    $operationCount
                )
            );
        }

        $params = [
            'source_account' => $this->muxedAccountToString($transaction->getSourceAccount()),
            'seq_num' => $transaction->getSequenceNumber()->toString(),
        ];

        $baseFee = (string) ($totalFee / $operationCount);
        if ($baseFee !== '100') {
            $params['fee'] = $baseFee;
        }

        $timeBounds = $transaction->getTimeBounds();
        if ($timeBounds !== null && ($timeBounds->getMinTime() > 0 || $timeBounds->getMaxTime() > 0)) {
            $params['cond'] = [
                'time' => [
                    'min_time' => $timeBounds->getMinTime() > 0 ? (string) $timeBounds->getMinTime() : '',
                    'max_time' => $timeBounds->getMaxTime() > 0 ? (string) $timeBounds->getMaxTime() : '',
                ],
            ];
        }

        $memo = $this->memoToLab($transaction->getMemo());
        if ($memo !== null) {
            $params['memo'] = $memo;
        }

        $state = [
            'network' => self::MAINNET,
            'transaction' => [
                'build' => [
                    'classic' => [
                        'operations' => array_map(
                            fn (AbstractOperation $operation): array => $this->normalizeClassicOperation($operation),
                            $operations
                        ),
                    ],
                    'params' => $params,
                    'isValid' => [
                        'params' => true,
                        'operations' => true,
                    ],
                ],
            ],
        ];

        return self::LAB_BASE_URL . '/transaction/build?$=' . $this->stringifyQueryValue($state) . ';;';
    }

    private function normalizeClassicOperation(AbstractOperation $operation): array
    {
        $normalized = match (true) {
            $operation instanceof CreateAccountOperation => [
                'operation_type' => 'create_account',
                'params' => [
                    'destination' => $this->muxedAccountToString($operation->getDestination()),
                    'starting_balance' => $operation->getStartingBalance(),
                ],
            ],
            $operation instanceof PaymentOperation => [
                'operation_type' => 'payment',
                'params' => [
                    'destination' => $this->muxedAccountToString($operation->getDestination()),
                    'asset' => $this->assetToLab($operation->getAsset()),
                    'amount' => $operation->getAmount(),
                ],
            ],
            $operation instanceof PathPaymentStrictSendOperation => [
                'operation_type' => 'path_payment_strict_send',
                'params' => array_filter([
                    'destination' => $this->muxedAccountToString($operation->getDestination()),
                    'send_asset' => $this->assetToLab($operation->getSendAsset()),
                    'send_amount' => $operation->getSendAmount(),
                    'dest_asset' => $this->assetToLab($operation->getDestAsset()),
                    'dest_min' => $operation->getDestMin(),
                    'path' => $operation->getPath() ? array_map(
                        fn (Asset $asset): array => $this->assetToLab($asset),
                        $operation->getPath()
                    ) : null,
                ], static fn (mixed $value): bool => $value !== null),
            ],
            $operation instanceof PathPaymentStrictReceiveOperation => [
                'operation_type' => 'path_payment_strict_receive',
                'params' => array_filter([
                    'destination' => $this->muxedAccountToString($operation->getDestination()),
                    'send_asset' => $this->assetToLab($operation->getSendAsset()),
                    'send_max' => $operation->getSendMax(),
                    'dest_asset' => $this->assetToLab($operation->getDestAsset()),
                    'dest_amount' => $operation->getDestAmount(),
                    'path' => $operation->getPath() ? array_map(
                        fn (Asset $asset): array => $this->assetToLab($asset),
                        $operation->getPath()
                    ) : null,
                ], static fn (mixed $value): bool => $value !== null),
            ],
            $operation instanceof ManageSellOfferOperation => [
                'operation_type' => 'manage_sell_offer',
                'params' => [
                    'selling' => $this->assetToLab($operation->getSelling()),
                    'buying' => $this->assetToLab($operation->getBuying()),
                    'amount' => $operation->getAmount(),
                    'price' => $this->priceToDecimalString($operation->getPrice()),
                    'offer_id' => (string) $operation->getOfferId(),
                ],
            ],
            $operation instanceof ManageBuyOfferOperation => [
                'operation_type' => 'manage_buy_offer',
                'params' => [
                    'selling' => $this->assetToLab($operation->getSelling()),
                    'buying' => $this->assetToLab($operation->getBuying()),
                    'buy_amount' => $operation->getAmount(),
                    'price' => $this->priceToDecimalString($operation->getPrice()),
                    'offer_id' => (string) $operation->getOfferId(),
                ],
            ],
            $operation instanceof CreatePassiveSellOfferOperation => [
                'operation_type' => 'create_passive_sell_offer',
                'params' => [
                    'selling' => $this->assetToLab($operation->getSelling()),
                    'buying' => $this->assetToLab($operation->getBuying()),
                    'amount' => $operation->getAmount(),
                    'price' => $this->priceToDecimalString($operation->getPrice()),
                ],
            ],
            $operation instanceof ChangeTrustOperation => [
                'operation_type' => 'change_trust',
                'params' => array_filter([
                    'line' => $this->assetToLab($operation->getAsset()),
                    'limit' => $operation->getLimit() !== self::MAX_TRUSTLINE_LIMIT ? $operation->getLimit() : null,
                ], static fn (mixed $value): bool => $value !== null),
            ],
            $operation instanceof AllowTrustOperation => [
                'operation_type' => 'allow_trust',
                'params' => [
                    'trustor' => $operation->getTrustor(),
                    'assetCode' => $operation->getAssetCode(),
                    'authorize' => (string) (
                        $operation->isAuthorizeToMaintainLiabilities()
                            ? 2
                            : ($operation->isAuthorize() ? 1 : 0)
                    ),
                ],
            ],
            $operation instanceof AccountMergeOperation => [
                'operation_type' => 'account_merge',
                'params' => [
                    'destination' => $this->muxedAccountToString($operation->getDestination()),
                ],
            ],
            $operation instanceof ManageDataOperation => [
                'operation_type' => 'manage_data',
                'params' => array_filter([
                    'data_name' => $operation->getKey(),
                    'data_value' => $operation->getValue(),
                ], static fn (mixed $value): bool => $value !== null),
            ],
            $operation instanceof BumpSequenceOperation => [
                'operation_type' => 'bump_sequence',
                'params' => [
                    'bump_to' => $operation->getBumpTo()->toString(),
                ],
            ],
            $operation instanceof ClaimClaimableBalanceOperation => [
                'operation_type' => 'claim_claimable_balance',
                'params' => [
                    'balance_id' => $this->claimableBalanceIdToString($operation->getBalanceId()),
                ],
            ],
            $operation instanceof BeginSponsoringFutureReservesOperation => [
                'operation_type' => 'begin_sponsoring_future_reserves',
                'params' => [
                    'sponsored_id' => $operation->getSponsoredId(),
                ],
            ],
            $operation instanceof EndSponsoringFutureReservesOperation => [
                'operation_type' => 'end_sponsoring_future_reserves',
                'params' => [],
            ],
            $operation instanceof ClawbackOperation => [
                'operation_type' => 'clawback',
                'params' => [
                    'asset' => $this->assetToLab($operation->getAsset()),
                    'from' => $this->muxedAccountToString($operation->getFrom()),
                    'amount' => $operation->getAmount(),
                ],
            ],
            $operation instanceof ClawbackClaimableBalanceOperation => [
                'operation_type' => 'clawback_claimable_balance',
                'params' => [
                    'balance_id' => $this->claimableBalanceIdToString($operation->getBalanceId()),
                ],
            ],
            $operation instanceof LiquidityPoolDepositOperation => [
                'operation_type' => 'liquidity_pool_deposit',
                'params' => [
                    'liquidity_pool_id' => $operation->getLiqudityPoolId(),
                    'max_amount_a' => $operation->getMaxAmountA(),
                    'max_amount_b' => $operation->getMaxAmountB(),
                    'min_price' => $this->priceToLab($operation->getMinPrice()),
                    'max_price' => $this->priceToLab($operation->getMaxPrice()),
                ],
            ],
            $operation instanceof LiquidityPoolWithdrawOperation => [
                'operation_type' => 'liquidity_pool_withdraw',
                'params' => [
                    'liquidity_pool_id' => $operation->getLiqudityPoolId(),
                    'amount' => $operation->getAmount(),
                    'min_amount_a' => $operation->getMinAmountA(),
                    'min_amount_b' => $operation->getMinAmountB(),
                ],
            ],
            default => throw new InvalidArgumentException(
                sprintf('Unsupported operation type: %s.', $operation::class)
            ),
        };

        $normalized['source_account'] = $operation->getSourceAccount() !== null
            ? $this->muxedAccountToString($operation->getSourceAccount())
            : '';

        return $normalized;
    }

    private function assetToLab(Asset $asset): array
    {
        if ($asset instanceof AssetTypeNative) {
            return [
                'type' => 'native',
                'code' => '',
                'issuer' => '',
            ];
        }

        if ($asset instanceof AssetTypeCreditAlphanum) {
            $code = $asset->getCode();
            return [
                'type' => strlen($code) <= 4 ? 'credit_alphanum4' : 'credit_alphanum12',
                'code' => $code,
                'issuer' => $asset->getIssuer(),
            ];
        }

        throw new InvalidArgumentException('Unsupported asset type in operation.');
    }

    private function memoToLab(Memo $memo): ?array
    {
        return match ($memo->getType()) {
            Memo::MEMO_TYPE_NONE => null,
            Memo::MEMO_TYPE_TEXT => ['text' => (string) $memo->getValue()],
            Memo::MEMO_TYPE_ID => ['id' => (string) $memo->getValue()],
            Memo::MEMO_TYPE_HASH => ['hash' => bin2hex((string) $memo->getValue())],
            Memo::MEMO_TYPE_RETURN => ['return' => bin2hex((string) $memo->getValue())],
            default => throw new InvalidArgumentException('Unsupported memo type.'),
        };
    }

    private function priceToLab(Price $price): array
    {
        return [
            'type' => 'fraction',
            'value' => [
                'n' => (string) $price->getN(),
                'd' => (string) $price->getD(),
            ],
        ];
    }

    private function priceToDecimalString(Price $price): string
    {
        $decimal = bcdiv((string) $price->getN(), (string) $price->getD(), 18);
        $decimal = rtrim(rtrim($decimal, '0'), '.');
        return $decimal !== '' ? $decimal : '0';
    }

    private function claimableBalanceIdToString(string $balanceId): string
    {
        return str_pad($balanceId, 72, '0', STR_PAD_LEFT);
    }

    private function muxedAccountToString(MuxedAccount|string $account): string
    {
        if ($account instanceof MuxedAccount) {
            return $account->getAccountId();
        }

        return $account;
    }

    private function stringifyQueryValue(mixed $value, bool $recursive = false): ?string
    {
        if (!$recursive) {
            $serialized = $this->stringifyQueryValue($value, true);
            if (!is_string($serialized)) {
                return $serialized;
            }
            return ltrim(rtrim($serialized, ';'), '$');
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = [];
                foreach ($value as $item) {
                    $items[] = $item === null ? ':null' : $this->stringifyQueryValue($item, true);
                }
                return '@' . implode('&', $items) . ';';
            }

            $items = [];
            foreach ($value as $key => $item) {
                $serializedItem = $this->stringifyQueryValue($item, true);
                if ($serializedItem === null) {
                    continue;
                }
                $items[] = $this->encodeObjectKey((string) $key) . $serializedItem;
            }
            return '$' . implode('&', $items) . ';';
        }

        if (is_int($value) || is_float($value)) {
            return ':' . $value;
        }

        if (is_bool($value)) {
            return $value ? ':true' : ':false';
        }

        if ($value === null) {
            return ':null';
        }

        if (is_string($value)) {
            return '=' . $this->encodeValue($value);
        }

        throw new InvalidArgumentException('Unsupported value while serializing Lab URL.');
    }

    private function encodeObjectKey(string $value): string
    {
        return $this->encodeUriCompat(preg_replace('/([=:@$\/])/', '/$1', $value) ?? $value);
    }

    private function encodeValue(string $value): string
    {
        return $this->encodeUriCompat(preg_replace('/([&;\/])/', '/$1', $value) ?? $value);
    }

    private function encodeUriCompat(string $value): string
    {
        $encoded = rawurlencode($value);

        return strtr($encoded, [
            '%3B' => ';',
            '%2C' => ',',
            '%2F' => '/',
            '%3F' => '?',
            '%3A' => ':',
            '%40' => '@',
            '%26' => '&',
            '%3D' => '=',
            '%2B' => '+',
            '%24' => '$',
            '%2D' => '-',
            '%5F' => '_',
            '%2E' => '.',
            '%21' => '!',
            '%7E' => '~',
            '%2A' => '*',
            '%27' => "'",
            '%28' => '(',
            '%29' => ')',
            '%23' => '#',
            '%25' => '%',
        ]);
    }
}
