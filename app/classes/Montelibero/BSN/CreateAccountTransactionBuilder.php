<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\BeginSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\CreateAccountOperationBuilder;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\EndSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\SetOptionsOperationBuilder;
use Soneso\StellarSDK\Signer;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\TransactionBuilderAccount;

final class CreateAccountTransactionBuilder
{
    public const MAX_OPERATIONS = 100;
    public const MAX_OPERATION_FEE = 10_000;
    public const MAX_AMOUNT = '922337203685.4775807';
    private const SCALE = 7;
    private const STROOPS_PER_XLM = 10_000_000;

    /**
     * @param list<Asset> $trustline_assets
     */
    public function build(
        TransactionBuilderAccount $SourceAccount,
        string $destination_account_id,
        string $starting_balance,
        array $trustline_assets,
        bool $sponsor,
        ?string $ownership_full_data_key,
        bool $lock_master_key,
    ): string {
        $source_account_id = $SourceAccount->getAccountId();
        $starting_balance = self::normalizeStartingBalance($starting_balance);
        if ($starting_balance === null) {
            throw new \InvalidArgumentException('Invalid starting balance.');
        }

        try {
            KeyPair::fromAccountId($source_account_id);
            KeyPair::fromAccountId($destination_account_id);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid account id.');
        }

        if ($source_account_id === $destination_account_id) {
            throw new \InvalidArgumentException('The source and destination accounts must differ.');
        }

        if (
            $ownership_full_data_key !== null
            && preg_match('/\AOwnershipFull[1-9]\d*\z/D', $ownership_full_data_key) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid ownership data key.');
        }

        $assets = [];
        foreach ($trustline_assets as $Asset) {
            if (!$Asset instanceof AssetTypeCreditAlphanum) {
                throw new \InvalidArgumentException('Invalid trustline asset.');
            }

            $key = $Asset->getType() . ':' . ($Asset->getCode() ?? '') . ':' . ($Asset->getIssuer() ?? '');
            if (isset($assets[$key])) {
                throw new \InvalidArgumentException('Duplicate trustline asset.');
            }
            $assets[$key] = $Asset;
        }

        $operation_count = self::operationCount(
            count($assets),
            $sponsor,
            $ownership_full_data_key !== null,
            $lock_master_key,
        );
        if ($operation_count > self::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('Too many operations.');
        }

        $Transaction = new TransactionBuilder($SourceAccount);
        $Transaction->setMaxOperationFee(self::MAX_OPERATION_FEE);

        if ($sponsor) {
            $Transaction->addOperation(
                (new BeginSponsoringFutureReservesOperationBuilder($destination_account_id))
                    ->setSourceAccount($source_account_id)
                    ->build(),
            );
        }

        $Transaction->addOperation(
            (new CreateAccountOperationBuilder($destination_account_id, $starting_balance))->build(),
        );

        foreach ($assets as $Asset) {
            $Transaction->addOperation(
                (new ChangeTrustOperationBuilder($Asset))
                    ->setSourceAccount($destination_account_id)
                    ->build(),
            );
        }

        if ($ownership_full_data_key !== null) {
            $Transaction->addOperation(
                (new ManageDataOperationBuilder('Owner', $source_account_id))
                    ->setSourceAccount($destination_account_id)
                    ->build(),
            );
        }

        if ($lock_master_key && $sponsor) {
            $Transaction->addOperation(
                (new SetOptionsOperationBuilder())
                    ->setSourceAccount($destination_account_id)
                    ->setSigner(Signer::ed25519PublicKey(KeyPair::fromAccountId($source_account_id)), 1)
                    ->build(),
            );
        }

        if ($sponsor) {
            $Transaction->addOperation(
                (new EndSponsoringFutureReservesOperationBuilder())
                    ->setSourceAccount($destination_account_id)
                    ->build(),
            );
        }

        if ($ownership_full_data_key !== null) {
            $Transaction->addOperation(
                (new ManageDataOperationBuilder($ownership_full_data_key, $destination_account_id))
                    ->setSourceAccount($source_account_id)
                    ->build(),
            );
        }

        if ($lock_master_key) {
            $SetOptions = (new SetOptionsOperationBuilder())
                ->setSourceAccount($destination_account_id)
                ->setMasterKeyWeight(0)
                ->setLowThreshold(1)
                ->setMediumThreshold(1)
                ->setHighThreshold(1);
            if (!$sponsor) {
                $SetOptions->setSigner(Signer::ed25519PublicKey(KeyPair::fromAccountId($source_account_id)), 1);
            }
            $Transaction->addOperation($SetOptions->build());
        }

        return $Transaction->build()->toEnvelopeXdrBase64();
    }

    public static function normalizeStartingBalance(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));
        if (preg_match('/\A\d+(?:\.\d{0,7})?\z/D', $value) !== 1) {
            return null;
        }

        $parts = explode('.', $value, 2);
        $normalized = (ltrim($parts[0], '0') ?: '0')
            . '.'
            . str_pad($parts[1] ?? '', self::SCALE, '0');

        if (bccomp($normalized, self::MAX_AMOUNT, self::SCALE) > 0) {
            return null;
        }

        return $normalized;
    }

    public static function operationCount(
        int $trustline_count,
        bool $sponsor,
        bool $full_ownership,
        bool $lock_master_key,
    ): int {
        if ($trustline_count < 0) {
            throw new \InvalidArgumentException('Invalid trustline count.');
        }

        return 1
            + $trustline_count
            + ($sponsor ? 2 : 0)
            + ($full_ownership ? 2 : 0)
            + ($lock_master_key ? ($sponsor ? 2 : 1) : 0);
    }

    public static function newAccountReserveEntries(
        int $trustline_count,
        bool $full_ownership,
        bool $lock_master_key,
    ): int {
        if ($trustline_count < 0) {
            throw new \InvalidArgumentException('Invalid trustline count.');
        }

        return 2 + $trustline_count + ($full_ownership ? 1 : 0) + ($lock_master_key ? 1 : 0);
    }

    public static function currentAccountReserveEntries(bool $full_ownership): int
    {
        return $full_ownership ? 1 : 0;
    }

    public static function requiresNewAccountSignature(
        int $trustline_count,
        bool $sponsor,
        bool $full_ownership,
        bool $lock_master_key,
    ): bool {
        if ($trustline_count < 0) {
            throw new \InvalidArgumentException('Invalid trustline count.');
        }

        return $trustline_count > 0 || $sponsor || $full_ownership || $lock_master_key;
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
