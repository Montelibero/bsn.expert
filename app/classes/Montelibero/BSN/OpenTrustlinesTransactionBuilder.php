<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\TransactionBuilderAccount;

final class OpenTrustlinesTransactionBuilder
{
    public const MAX_OPERATIONS = 100;
    public const MAX_OPERATION_FEE = 10_000;
    private const SCALE = 7;
    private const STROOPS_PER_XLM = 10_000_000;

    /**
     * @param list<Asset> $assets
     */
    public function build(TransactionBuilderAccount $SourceAccount, array $assets): string
    {
        $count = count($assets);
        if ($count < 1 || $count > self::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('A trustline transaction must contain between 1 and 100 operations.');
        }

        $Transaction = new TransactionBuilder($SourceAccount);
        $Transaction->setMaxOperationFee(self::MAX_OPERATION_FEE);
        $seen = [];

        foreach ($assets as $Asset) {
            if (!$Asset instanceof AssetTypeCreditAlphanum) {
                throw new \InvalidArgumentException('A trustline asset must be a credit asset.');
            }

            $code = $Asset->getCode();
            $issuer = $Asset->getIssuer();
            $key = $code . '-' . $issuer;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Duplicate trustline asset.');
            }
            $seen[$key] = true;

            $Transaction->addOperation((new ChangeTrustOperationBuilder($Asset))->build());
        }

        return $Transaction->build()->toEnvelopeXdrBase64();
    }

    public static function maxFeeXlm(int $operation_count): string
    {
        if ($operation_count < 0 || $operation_count > self::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('Invalid operation count.');
        }

        return bcdiv(
            (string) ($operation_count * self::MAX_OPERATION_FEE),
            (string) self::STROOPS_PER_XLM,
            self::SCALE,
        );
    }
}
