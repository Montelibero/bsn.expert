<?php

declare(strict_types=1);

use Montelibero\BSN\TransactionConsolidationMemo;
use Montelibero\BSN\TransactionConsolidator;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Claimant;
use Soneso\StellarSDK\CreateAccountOperation;
use Soneso\StellarSDK\CreateClaimableBalanceOperation;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\PaymentOperation;
use Soneso\StellarSDK\Xdr\XdrDataValue;
use Soneso\StellarSDK\Xdr\XdrDecoratedSignature;
use Soneso\StellarSDK\Xdr\XdrEncoder;
use Soneso\StellarSDK\Xdr\XdrEnvelopeType;
use Soneso\StellarSDK\Xdr\XdrExtensionPoint;
use Soneso\StellarSDK\Xdr\XdrFeeBumpTransaction;
use Soneso\StellarSDK\Xdr\XdrFeeBumpTransactionEnvelope;
use Soneso\StellarSDK\Xdr\XdrFeeBumpTransactionInnerTx;
use Soneso\StellarSDK\Xdr\XdrManageDataOperation;
use Soneso\StellarSDK\Xdr\XdrMemo;
use Soneso\StellarSDK\Xdr\XdrMemoType;
use Soneso\StellarSDK\Xdr\XdrMuxedAccount;
use Soneso\StellarSDK\Xdr\XdrMuxedAccountMed25519;
use Soneso\StellarSDK\Xdr\XdrOperation;
use Soneso\StellarSDK\Xdr\XdrOperationBody;
use Soneso\StellarSDK\Xdr\XdrOperationType;
use Soneso\StellarSDK\Xdr\XdrPreconditions;
use Soneso\StellarSDK\Xdr\XdrPreconditionType;
use Soneso\StellarSDK\Xdr\XdrRestoreFootprintOp;
use Soneso\StellarSDK\Xdr\XdrSequenceNumber;
use Soneso\StellarSDK\Xdr\XdrTransaction;
use Soneso\StellarSDK\Xdr\XdrTransactionEnvelope;
use Soneso\StellarSDK\Xdr\XdrTransactionV1Envelope;
use phpseclib3\Math\BigInteger;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertConsolidationSame(mixed $expected, mixed $actual, string $message): void
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

function assertConsolidationTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertConsolidationThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function consolidationAccount(string $accountId): XdrMuxedAccount
{
    return new XdrMuxedAccount(StrKey::decodeAccountId($accountId));
}

function consolidationMemoText(string $value): XdrMemo
{
    $Memo = new XdrMemo(new XdrMemoType(XdrMemoType::MEMO_TEXT));
    $Memo->setText($value);

    return $Memo;
}

function consolidationManageData(string $key, ?string $value, ?string $source = null): XdrOperation
{
    $Body = new XdrOperationBody(new XdrOperationType(XdrOperationType::MANAGE_DATA));
    $Body->setManageDataOperation(new XdrManageDataOperation($key, new XdrDataValue($value)));

    return new XdrOperation($Body, $source === null ? null : consolidationAccount($source));
}

/** @param list<XdrOperation> $operations */
function consolidationTransaction(
    string $source,
    array $operations,
    XdrMemo $Memo,
    string $sequence = '123',
): XdrTransaction {
    return new XdrTransaction(
        consolidationAccount($source),
        new XdrSequenceNumber(new BigInteger($sequence, 10)),
        $operations,
        count($operations) * 100,
        $Memo,
        new XdrPreconditions(new XdrPreconditionType(XdrPreconditionType::NONE)),
    );
}

/** @param list<XdrDecoratedSignature> $signatures */
function consolidationV1Envelope(XdrTransaction $Transaction, array $signatures = []): string
{
    $Envelope = new XdrTransactionEnvelope(new XdrEnvelopeType(XdrEnvelopeType::ENVELOPE_TYPE_TX));
    $Envelope->setV1(new XdrTransactionV1Envelope($Transaction, $signatures));

    return $Envelope->toBase64Xdr();
}

function consolidationSignature(string $value): XdrDecoratedSignature
{
    return new XdrDecoratedSignature('hint', $value);
}

/**
 * The SDK version in this project loses a present-but-empty ManageData value
 * while encoding. This fixture writes that valid protocol form explicitly.
 */
function consolidationEmptyValueEnvelope(string $source): string
{
    $Memo = TransactionConsolidationMemo::none();
    $bytes = XdrEncoder::integer32(XdrEnvelopeType::ENVELOPE_TYPE_TX);
    $bytes .= consolidationAccount($source)->encode();
    $bytes .= XdrEncoder::unsignedInteger32(100);
    $bytes .= (new XdrSequenceNumber(new BigInteger('124', 10)))->encode();
    $bytes .= XdrEncoder::integer32(XdrPreconditionType::NONE);
    $bytes .= $Memo->toXdr()->encode();
    $bytes .= XdrEncoder::integer32(1);
    $bytes .= XdrEncoder::integer32(0); // Operation has no explicit source.
    $bytes .= XdrEncoder::integer32(XdrOperationType::MANAGE_DATA);
    $bytes .= XdrEncoder::string('empty', 64);
    $bytes .= XdrEncoder::integer32(1); // Value pointer is present.
    $bytes .= XdrEncoder::opaqueVariable('');
    $bytes .= XdrEncoder::integer32(0); // TransactionExt v0.
    $bytes .= XdrEncoder::integer32(0); // No signatures.

    return base64_encode($bytes);
}

function consolidationInflationEnvelope(string $source): string
{
    $bytes = XdrEncoder::integer32(XdrEnvelopeType::ENVELOPE_TYPE_TX);
    $bytes .= consolidationAccount($source)->encode();
    $bytes .= XdrEncoder::unsignedInteger32(100);
    $bytes .= (new XdrSequenceNumber(new BigInteger('131', 10)))->encode();
    $bytes .= XdrEncoder::integer32(XdrPreconditionType::NONE);
    $bytes .= TransactionConsolidationMemo::none()->toXdr()->encode();
    $bytes .= XdrEncoder::integer32(1);
    $bytes .= XdrEncoder::integer32(0); // Operation has no explicit source.
    $bytes .= XdrEncoder::integer32(XdrOperationType::INFLATION);
    $bytes .= XdrEncoder::integer32(0); // TransactionExt v0.
    $bytes .= XdrEncoder::integer32(0); // No signatures.

    return base64_encode($bytes);
}

/**
 * @param list<XdrOperation> $operations
 * @param list<XdrDecoratedSignature> $signatures
 */
function consolidationV0Envelope(string $source, array $operations, XdrMemo $Memo, array $signatures = []): string
{
    $bytes = XdrEncoder::integer32(XdrEnvelopeType::ENVELOPE_TYPE_TX_V0);
    $bytes .= StrKey::decodeAccountId($source);
    $bytes .= XdrEncoder::unsignedInteger32(count($operations) * 100);
    $bytes .= (new XdrSequenceNumber(new BigInteger('125', 10)))->encode();
    $bytes .= XdrEncoder::integer32(0); // No time bounds.
    $bytes .= $Memo->encode();
    $bytes .= XdrEncoder::integer32(count($operations));
    foreach ($operations as $Operation) {
        $bytes .= $Operation->encode();
    }
    $bytes .= XdrEncoder::integer32(0); // TransactionV0Ext v0.
    $bytes .= XdrEncoder::integer32(count($signatures));
    foreach ($signatures as $Signature) {
        $bytes .= $Signature->encode();
    }

    return base64_encode($bytes);
}

$Consolidator = new TransactionConsolidator();
$sourceA = KeyPair::random()->getAccountId();
$sourceB = KeyPair::random()->getAccountId();
$sourceC = KeyPair::random()->getAccountId();
$sourceD = KeyPair::random()->getAccountId();
$Memo = consolidationMemoText('mutual love');
$operations = [
    consolidationManageData('Love', $sourceB),
    consolidationManageData('Love', $sourceA, $sourceB),
];
$Transaction = consolidationTransaction($sourceA, $operations, $Memo);
$unsignedXdr = consolidationV1Envelope($Transaction);
$signedXdr = consolidationV1Envelope($Transaction, [consolidationSignature('signed')]);

$Item = $Consolidator->parseEnvelope($signedXdr);
assertConsolidationSame('v1', $Item->envelope_type, 'V1 envelope type must be exposed.');
assertConsolidationSame($sourceA, $Item->source, 'Transaction source must be decoded.');
assertConsolidationSame('text', $Item->memo->type, 'Text memo type must be retained.');
assertConsolidationSame('mutual love', $Item->memo->value, 'Text memo value must be retained.');
assertConsolidationSame(1, $Item->signature_count, 'Signature count must be exposed.');
assertConsolidationTrue(in_array('signatures_discarded', $Item->warnings, true), 'Signed input needs a warning.');
assertConsolidationSame(2, $Item->operation_count, 'All operations must be described.');
assertConsolidationSame(null, $Item->operations[0]['source'], 'Inherited operation source must remain null in metadata.');
assertConsolidationSame($sourceA, $Item->operations[0]['effective_source'], 'Inherited source must resolve to the original transaction source.');
assertConsolidationSame($sourceB, $Item->operations[1]['source'], 'Explicit operation source must be retained.');
assertConsolidationSame('Love', $Item->operations[0]['details']['key'], 'ManageData key must be exposed raw.');
assertConsolidationSame(false, $Item->operations[0]['details']['delete'], 'ManageData set must not be described as deletion.');

$DescribedItem = $Consolidator->parseEnvelope(consolidationV1Envelope(consolidationTransaction(
    $sourceA,
    [
        (new PaymentOperation(new MuxedAccount($sourceB), Asset::native(), '2.5000000'))->toXdr(),
        (new CreateAccountOperation($sourceC, '3.0000000'))->toXdr(),
    ],
    TransactionConsolidationMemo::none()->toXdr(),
    '133',
)));
assertConsolidationSame(
    'Payment: 2.5000000 XLM to ' . $sourceB,
    $DescribedItem->operations[0]['summary'],
    'Payment summary must expose amount, asset, and destination.',
);
assertConsolidationSame('native', $DescribedItem->operations[0]['details']['asset'], 'Payment details must use canonical asset form.');
assertConsolidationSame(
    'Create account: ' . $sourceC . ' with 3.0000000 XLM',
    $DescribedItem->operations[1]['summary'],
    'CreateAccount summary must expose destination and starting balance.',
);
assertConsolidationSame($sourceC, $DescribedItem->operations[1]['details']['destination'], 'CreateAccount details must expose destination.');

$ClaimableItem = $Consolidator->parseEnvelope(consolidationV1Envelope(consolidationTransaction(
    $sourceA,
    [new XdrOperation((new CreateClaimableBalanceOperation(
        [new Claimant($sourceB, Claimant::predicateUnconditional())],
        Asset::native(),
        '1.0000000',
    ))->toOperationBody())],
    TransactionConsolidationMemo::none()->toXdr(),
)));
assertConsolidationTrue(
    in_array('claimable_balance_id_changes', $ClaimableItem->warnings, true),
    'CreateClaimableBalance must warn that the derived balance ID changes after consolidation.',
);

$UnsignedItem = $Consolidator->parseEnvelope($unsignedXdr);
assertConsolidationSame($Item->id, $UnsignedItem->id, 'Signatures must not change the transaction ID.');
assertConsolidationTrue($Item->fingerprint !== $UnsignedItem->fingerprint, 'Envelope fingerprint must include signatures.');

$import = $Consolidator->importLines(implode("\n", [
    $signedXdr,
    $unsignedXdr,
    'not-base64',
    base64_encode(base64_decode($unsignedXdr, true) . 'junk'),
]));
assertConsolidationSame(1, count($import['items']), 'Bulk import must keep its valid unique transaction.');
assertConsolidationSame(1, count($import['duplicates']), 'Bulk import must deduplicate signed and unsigned forms.');
assertConsolidationSame(2, count($import['errors']), 'Bulk import must report invalid lines independently.');

$hashMemo = TransactionConsolidationMemo::fromCustom(str_repeat('ab', 32));
$builtXdr = $Consolidator->build(
    [$Item],
    [$Item->id => [0, 1]],
    $sourceC,
    $hashMemo,
    '9223372036854775807',
);
$Built = $Consolidator->parseEnvelope($builtXdr);
assertConsolidationSame($sourceC, $Built->source, 'Selected final source must be used.');
assertConsolidationSame('hash', $Built->memo->type, 'Custom 64-hex memo must become MEMO_HASH.');
assertConsolidationSame(0, $Built->signature_count, 'Built transaction must be unsigned.');
assertConsolidationSame($sourceA, $Built->operations[0]['source'], 'Inherited input source must become explicit in output.');
assertConsolidationSame($sourceB, $Built->operations[1]['source'], 'Explicit input source must stay explicit in output.');

$sameSourceBuilt = $Consolidator->parseEnvelope($Consolidator->build(
    [$Item],
    [$Item->id => [0, 1]],
    $sourceA,
    TransactionConsolidationMemo::none(),
    126,
));
assertConsolidationSame(null, $sameSourceBuilt->operations[0]['source'], 'Operation source equal to transaction source must be omitted.');
assertConsolidationSame($sourceB, $sameSourceBuilt->operations[1]['source'], 'A different operation source must remain explicit.');

$muxed = new XdrMuxedAccountMed25519(0, StrKey::decodeAccountId($sourceD));
$muxedSource = StrKey::encodeMuxedAccountId($muxed->encodeInverted());
$muxedXdr = $Consolidator->build([$Item], [$Item->id => [0]], $muxedSource, TransactionConsolidationMemo::none(), 126);
assertConsolidationSame($muxedSource, $Consolidator->parseEnvelope($muxedXdr)->source, 'Muxed source with ID zero must not collapse to a G address.');

$emptyValueItem = $Consolidator->parseEnvelope(consolidationEmptyValueEnvelope($sourceA));
assertConsolidationSame('', $emptyValueItem->operations[0]['details']['value'], 'Present empty ManageData value must remain present.');
assertConsolidationSame(false, $emptyValueItem->operations[0]['details']['delete'], 'Present empty ManageData value is not deletion.');
$emptyBuilt = $Consolidator->build(
    [$emptyValueItem],
    [$emptyValueItem->id => [0]],
    $sourceC,
    TransactionConsolidationMemo::none(),
    127,
);
assertConsolidationSame('', $Consolidator->parseEnvelope($emptyBuilt)->operations[0]['details']['value'], 'Build must preserve a present empty ManageData value.');

$InflationItem = $Consolidator->parseEnvelope(consolidationInflationEnvelope($sourceA));
assertConsolidationSame(XdrOperationType::INFLATION, $InflationItem->operations[0]['type'], 'Classic inflation operation must be accepted.');
$inflationBuilt = $Consolidator->build(
    [$InflationItem],
    [$InflationItem->id => [0]],
    $sourceC,
    TransactionConsolidationMemo::none(),
    132,
);
assertConsolidationSame(XdrOperationType::INFLATION, $Consolidator->parseEnvelope($inflationBuilt)->operations[0]['type'], 'Build must preserve classic inflation operation.');

$v0Item = $Consolidator->parseEnvelope(consolidationV0Envelope(
    $sourceA,
    [$operations[0]],
    $Memo,
    [consolidationSignature('legacy')],
));
assertConsolidationSame('v0', $v0Item->envelope_type, 'V0 input must be accepted.');
assertConsolidationSame(1, $v0Item->signature_count, 'Signed V0 input must be accepted.');
assertConsolidationTrue(in_array('legacy_v0_normalized', $v0Item->warnings, true), 'V0 normalization needs a warning.');

$InnerEnvelope = new XdrTransactionV1Envelope($Transaction, [consolidationSignature('inner')]);
$FeeBump = new XdrFeeBumpTransaction(
    consolidationAccount($sourceD),
    10_000,
    new XdrFeeBumpTransactionInnerTx(
        new XdrEnvelopeType(XdrEnvelopeType::ENVELOPE_TYPE_TX),
        $InnerEnvelope,
    ),
);
$FeeBumpEnvelope = new XdrTransactionEnvelope(new XdrEnvelopeType(XdrEnvelopeType::ENVELOPE_TYPE_TX_FEE_BUMP));
$FeeBumpEnvelope->setFeeBump(new XdrFeeBumpTransactionEnvelope($FeeBump, [consolidationSignature('outer')]));
$FeeBumpItem = $Consolidator->parseEnvelope($FeeBumpEnvelope->toBase64Xdr());
assertConsolidationSame('fee_bump', $FeeBumpItem->envelope_type, 'Fee-bump envelope must expose its type.');
assertConsolidationSame($UnsignedItem->id, $FeeBumpItem->id, 'Fee-bump ID must identify its inner transaction.');
assertConsolidationSame(2, $FeeBumpItem->signature_count, 'Inner and outer signatures must both be counted.');
assertConsolidationTrue(in_array('fee_bump_outer_discarded', $FeeBumpItem->warnings, true), 'Discarded fee-bump wrapper needs a warning.');

$SorobanBody = new XdrOperationBody(new XdrOperationType(XdrOperationType::RESTORE_FOOTPRINT));
$SorobanBody->setRestoreFootprintOp(new XdrRestoreFootprintOp(new XdrExtensionPoint(0)));
$SorobanXdr = consolidationV1Envelope(consolidationTransaction(
    $sourceA,
    [new XdrOperation($SorobanBody)],
    TransactionConsolidationMemo::none()->toXdr(),
));
assertConsolidationThrows(
    static fn () => $Consolidator->parseEnvelope($SorobanXdr),
    'Soroban operations must be rejected.',
);

assertConsolidationThrows(
    static fn () => $Consolidator->parseEnvelope($unsignedXdr . '!!!!'),
    'Base64 garbage must be rejected.',
);
assertConsolidationThrows(
    static fn () => $Consolidator->parseEnvelope(base64_encode(base64_decode($unsignedXdr, true) . 'junk')),
    'Trailing decoded XDR bytes must be rejected.',
);
$loginChallengeXdr = consolidationV1Envelope(consolidationTransaction(
    $sourceA,
    [consolidationManageData('web_auth_domain', 'bsn.expert')],
    TransactionConsolidationMemo::none()->toXdr(),
    '0',
));
assertConsolidationThrows(
    static fn () => $Consolidator->parseEnvelope($loginChallengeXdr),
    'SEP-10/login challenge envelopes with sequence zero must be rejected.',
);
assertConsolidationThrows(
    static fn () => TransactionConsolidationMemo::fromCustom(str_repeat('ü', 15)),
    'Text memo byte limit must be enforced.',
);
assertConsolidationSame('none', TransactionConsolidationMemo::fromCustom('')->type, 'Empty custom memo must mean none.');
assertConsolidationSame('text', TransactionConsolidationMemo::fromCustom('hello')->type, 'Non-hash custom memo must mean text.');

$memoCandidates = $Consolidator->memoCandidates([$Item, $UnsignedItem]);
assertConsolidationSame(2, count($memoCandidates), 'Memo candidates must contain none and one deduplicated text memo.');
assertConsolidationSame('none', $memoCandidates[0]->type, 'None must be the first memo candidate.');

$manyOperations = [];
for ($index = 0; $index < 100; $index++) {
    $manyOperations[] = consolidationManageData('key-' . $index, 'value');
}
$ManyItem = $Consolidator->parseEnvelope(consolidationV1Envelope(consolidationTransaction(
    $sourceA,
    $manyOperations,
    TransactionConsolidationMemo::none()->toXdr(),
    '128',
)));
$OneItem = $Consolidator->parseEnvelope(consolidationV1Envelope(consolidationTransaction(
    $sourceA,
    [consolidationManageData('one-more', 'value')],
    TransactionConsolidationMemo::none()->toXdr(),
    '129',
)));
assertConsolidationThrows(
    static fn () => $Consolidator->build(
        [$ManyItem, $OneItem],
        [
            $ManyItem->id => range(0, 99),
            $OneItem->id => [0],
        ],
        $sourceA,
        TransactionConsolidationMemo::none(),
        130,
    ),
    'Build must reject a 101-operation result.',
);

fwrite(STDOUT, "Transaction consolidator regression test passed.\n");
