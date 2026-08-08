<?php

declare(strict_types=1);

use Montelibero\BSN\PaymentDestination;
use Montelibero\BSN\PaymentMemo;
use Montelibero\BSN\PaymentTransactionBuilder;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\PaymentOperation;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\Xdr\XdrEncoder;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertPaymentSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertPaymentTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertPaymentThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function paymentSourceAccount(string $account_id, string $sequence = '41'): AccountResponse
{
    return AccountResponse::fromJson([
        'account_id' => $account_id,
        'sequence' => $sequence,
    ]);
}

$source = KeyPair::random()->getAccountId();
$destination = KeyPair::random()->getAccountId();
$muxed_base = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$muxed_zero = StrKey::encodeMuxedAccountId(
    StrKey::decodeAccountId($muxed_base) . XdrEncoder::unsignedInteger64(0),
);
$muxed_max = StrKey::encodeMuxedAccountId(
    StrKey::decodeAccountId($muxed_base) . str_repeat("\xff", 8),
);

$GDestination = PaymentDestination::fromAddress($destination);
$MuxedZeroDestination = PaymentDestination::fromAddress($muxed_zero);
assertPaymentSame($destination, $GDestination->address, 'G destination must be retained exactly.');
assertPaymentSame($destination, $GDestination->account_id, 'G destination must be its own Horizon account.');
assertPaymentSame($muxed_zero, $MuxedZeroDestination->address, 'Muxed destination must be retained exactly.');
assertPaymentSame($muxed_base, $MuxedZeroDestination->account_id, 'Muxed destination must expose its base Horizon account.');
assertPaymentTrue($MuxedZeroDestination->isMuxed(), 'Muxed destination must be identified as muxed.');
$MuxedMaxDestination = PaymentDestination::fromAddress($muxed_max);
assertPaymentSame($muxed_max, $MuxedMaxDestination->address, 'Maximum uint64 muxed destination must be retained exactly.');
assertPaymentSame($muxed_base, $MuxedMaxDestination->account_id, 'Maximum uint64 muxed destination must expose its base account.');

$invalid_destination = substr($destination, 0, -1) . ($destination[-1] === 'A' ? 'B' : 'A');
assertPaymentThrows(
    static fn () => PaymentDestination::fromAddress($invalid_destination),
    'A destination with an invalid checksum must be rejected.',
);

$Builder = new PaymentTransactionBuilder();
$CreditAsset = Asset::createNonNativeAsset('TEST', $issuer);
$xdr = $Builder->build(
    paymentSourceAccount($source),
    [
        [
            'destination' => $GDestination,
            'asset' => Asset::native(),
            'amount' => '1.5000000',
        ],
        [
            'destination' => $MuxedZeroDestination,
            'asset' => $CreditAsset,
            'amount' => '2.2500000',
        ],
    ],
    PaymentMemo::fromInput('Batch payment')->memo,
);

$Transaction = AbstractTransaction::fromEnvelopeBase64XdrString($xdr);
assertPaymentTrue($Transaction instanceof Transaction, 'Builder must produce a regular Stellar transaction.');
assertPaymentSame($source, $Transaction->getSourceAccount()->getAccountId(), 'Transaction source must be retained.');
assertPaymentSame('42', $Transaction->getSequenceNumber()->toString(), 'Transaction must use the next source sequence.');
assertPaymentSame(20_000, $Transaction->getFee(), 'Maximum fee must scale with the operation count.');
assertPaymentSame('text', $Transaction->getMemo()->typeAsString(), 'Detected text memo must be encoded as text.');
assertPaymentSame('Batch payment', $Transaction->getMemo()->valueAsString(), 'Text memo value must be retained.');

$operations = $Transaction->getOperations();
assertPaymentSame(2, count($operations), 'Every requested payment must become one operation.');
assertPaymentTrue($operations[0] instanceof PaymentOperation, 'First operation must be Payment.');
assertPaymentTrue($operations[1] instanceof PaymentOperation, 'Second operation must be Payment.');
assertPaymentSame($destination, $operations[0]->getDestination()->getAccountId(), 'G destination must survive XDR round-trip.');
assertPaymentSame('1.5000000', $operations[0]->getAmount(), 'First amount must survive XDR round-trip.');
assertPaymentSame(0, $operations[1]->getDestination()->getId(), 'Muxed ID zero must not collapse into a G destination.');
assertPaymentSame($muxed_base, $operations[1]->getDestination()->getEd25519AccountId(), 'Muxed base account must survive XDR round-trip.');
assertPaymentSame('TEST', $operations[1]->getAsset()->getCode(), 'Credit asset must survive XDR round-trip.');
assertPaymentSame($issuer, $operations[1]->getAsset()->getIssuer(), 'Credit issuer must survive XDR round-trip.');
assertPaymentSame('2.2500000', $operations[1]->getAmount(), 'Second amount must survive XDR round-trip.');

$max_muxed_xdr = $Builder->build(
    paymentSourceAccount($source),
    [[
        'destination' => $MuxedMaxDestination,
        'asset' => Asset::native(),
        'amount' => '0.0000001',
    ]],
    PaymentMemo::fromInput('')->memo,
);
$MaxMuxedTransaction = AbstractTransaction::fromEnvelopeBase64XdrString($max_muxed_xdr);
assertPaymentTrue($MaxMuxedTransaction instanceof Transaction, 'Maximum uint64 muxed payment must produce a transaction.');
assertPaymentSame(
    $muxed_max,
    $MaxMuxedTransaction->getOperations()[0]->getDestination()->getAccountId(),
    'Maximum uint64 muxed destination must survive XDR round-trip.',
);

foreach ([
    '1' => '1.0000000',
    '1.' => '1.0000000',
    '1,25' => '1.2500000',
    '0.0000001' => '0.0000001',
    PaymentTransactionBuilder::MAX_AMOUNT => PaymentTransactionBuilder::MAX_AMOUNT,
] as $input => $expected) {
    assertPaymentSame($expected, PaymentTransactionBuilder::normalizeAmount($input), 'Valid amount must be normalized.');
}
foreach (['', '0', '-1', '.5', '1e2', '1.00000001', '922337203685.4775808'] as $input) {
    assertPaymentSame(null, PaymentTransactionBuilder::normalizeAmount($input), 'Invalid amount must be rejected.');
}
assertPaymentSame(null, PaymentTransactionBuilder::normalizeAmount([]), 'Array amount must be rejected.');
assertPaymentSame('0.0020000', PaymentTransactionBuilder::maxFeeXlm(2), 'Fee must use 10000 stroops per operation.');

$NoneMemo = PaymentMemo::fromInput('');
assertPaymentSame('none', $NoneMemo->type, 'Empty memo input must select no memo.');
$IdMemo = PaymentMemo::fromInput('123');
assertPaymentSame('id', $IdMemo->type, 'Bare integer memo must be detected as ID.');
assertPaymentSame('123', $IdMemo->memo->valueAsString(), 'ID memo value must be retained.');
$TextMemo = PaymentMemo::fromInput('invoice:123');
assertPaymentSame('text', $TextMemo->type, 'Ordinary text memo must be detected as TEXT.');
assertPaymentSame('invoice:123', $TextMemo->value, 'Text memo value must be retained exactly.');
$hash = str_repeat('ab', 32);
$HashMemo = PaymentMemo::fromInput($hash);
assertPaymentSame('hash', $HashMemo->type, 'Bare 64-character hex memo must be detected as HASH.');
assertPaymentSame($hash, $HashMemo->value, 'Hash memo must retain normalized hex for preview.');
assertPaymentSame(str_repeat('я', 14), PaymentMemo::fromInput(str_repeat('я', 14))->value, 'A 28-byte UTF-8 memo must be accepted.');
assertPaymentThrows(
    static fn () => PaymentMemo::fromInput(str_repeat('я', 15)),
    'A text memo longer than 28 bytes must be rejected.',
);
assertPaymentSame((string) PHP_INT_MAX, PaymentMemo::fromInput((string) PHP_INT_MAX)->value, 'Largest supported ID must be accepted.');
assertPaymentThrows(
    static fn () => PaymentMemo::fromInput('9223372036854775808'),
    'ID larger than the SDK integer range must be rejected.',
);

assertPaymentThrows(
    static fn () => $Builder->build(paymentSourceAccount($source), [], $NoneMemo->memo),
    'An empty payment transaction must be rejected.',
);
$too_many = [];
for ($i = 0; $i <= PaymentTransactionBuilder::MAX_OPERATIONS; $i++) {
    $too_many[] = [
        'destination' => $GDestination,
        'asset' => Asset::native(),
        'amount' => '0.0000001',
    ];
}
assertPaymentThrows(
    static fn () => $Builder->build(paymentSourceAccount($source), $too_many, $NoneMemo->memo),
    'A transaction with 101 payment operations must be rejected.',
);

$maximum = array_slice($too_many, 0, PaymentTransactionBuilder::MAX_OPERATIONS);
$maximum_xdr = $Builder->build(paymentSourceAccount($source), $maximum, $NoneMemo->memo);
$MaximumTransaction = AbstractTransaction::fromEnvelopeBase64XdrString($maximum_xdr);
assertPaymentTrue($MaximumTransaction instanceof Transaction, 'A transaction with 100 payments must be accepted.');
assertPaymentSame(100, count($MaximumTransaction->getOperations()), 'The protocol operation limit must be preserved.');
assertPaymentSame(1_000_000, $MaximumTransaction->getFee(), 'Fee for 100 operations must be encoded exactly.');

fwrite(STDOUT, "Payment transaction builder tests passed.\n");
