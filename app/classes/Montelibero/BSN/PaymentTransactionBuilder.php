<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\TransactionBuilderAccount;
use Soneso\StellarSDK\Xdr\XdrOperationBody;
use Soneso\StellarSDK\Xdr\XdrOperationType;
use Soneso\StellarSDK\Xdr\XdrPaymentOperation;

final class PaymentTransactionBuilder
{
    public const MAX_OPERATIONS = 100;
    public const MAX_OPERATION_FEE = 10_000;
    public const MAX_AMOUNT = '922337203685.4775807';
    private const SCALE = 7;
    private const STROOPS_PER_XLM = 10_000_000;

    /**
     * @param list<array{destination: PaymentDestination, asset: Asset, amount: string}> $payments
     */
    public function build(TransactionBuilderAccount $SourceAccount, array $payments, Memo $Memo): string
    {
        $count = count($payments);
        if ($count < 1 || $count > self::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('A payment transaction must contain between 1 and 100 operations.');
        }

        $Transaction = new TransactionBuilder($SourceAccount);
        $Transaction->setMaxOperationFee(self::MAX_OPERATION_FEE);
        $Transaction->addMemo($Memo);

        foreach ($payments as $payment) {
            $Destination = $payment['destination'] ?? null;
            $Asset = $payment['asset'] ?? null;
            $amount = self::normalizeAmount($payment['amount'] ?? null);
            if (!$Destination instanceof PaymentDestination || !$Asset instanceof Asset || $amount === null) {
                throw new \InvalidArgumentException('Invalid payment operation.');
            }

            $Transaction->addOperation(new PaymentTransactionOperation($Destination, $Asset, $amount));
        }

        return $Transaction->build()->toEnvelopeXdrBase64();
    }

    public static function normalizeAmount(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));
        if (preg_match('/\A\d+(?:\.\d{0,7})?\z/D', $value) !== 1) {
            return null;
        }

        $parts = explode('.', $value, 2);
        $normalized = ltrim($parts[0], '0');
        $normalized = ($normalized === '' ? '0' : $normalized)
            . '.'
            . str_pad($parts[1] ?? '', self::SCALE, '0');

        if (
            bccomp($normalized, '0', self::SCALE) <= 0
            || bccomp($normalized, self::MAX_AMOUNT, self::SCALE) > 0
        ) {
            return null;
        }

        return $normalized;
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

final class PaymentTransactionOperation extends AbstractOperation
{
    public function __construct(
        private readonly PaymentDestination $Destination,
        private readonly Asset $Asset,
        private readonly string $amount,
    ) {
    }

    public function toOperationBody(): XdrOperationBody
    {
        $Payment = new XdrPaymentOperation(
            $this->Destination->toXdr(),
            $this->Asset->toXdr(),
            self::toXdrAmount($this->amount),
        );
        $Body = new XdrOperationBody(new XdrOperationType(XdrOperationType::PAYMENT));
        $Body->setPaymentOp($Payment);

        return $Body;
    }
}
