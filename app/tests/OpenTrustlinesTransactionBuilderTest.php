<?php

declare(strict_types=1);

use Montelibero\BSN\OpenTrustlinesTransactionBuilder;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ChangeTrustOperation;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Transaction;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertOpenTrustlinesSame(mixed $expected, mixed $actual, string $message): void
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

function assertOpenTrustlinesTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertOpenTrustlinesThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function openTrustlinesSourceAccount(string $account_id, string $sequence = '41'): AccountResponse
{
    return AccountResponse::fromJson([
        'account_id' => $account_id,
        'sequence' => $sequence,
    ]);
}

$source = KeyPair::random()->getAccountId();
$issuer_a = KeyPair::random()->getAccountId();
$issuer_b = KeyPair::random()->getAccountId();
$AssetA = Asset::createNonNativeAsset('TEST', $issuer_a);
$AssetB = Asset::createNonNativeAsset('LONGTOKEN', $issuer_b);
$Builder = new OpenTrustlinesTransactionBuilder();

$xdr = $Builder->build(openTrustlinesSourceAccount($source), [$AssetA, $AssetB]);
$Transaction = AbstractTransaction::fromEnvelopeBase64XdrString($xdr);
assertOpenTrustlinesTrue($Transaction instanceof Transaction, 'Builder must produce a regular Stellar transaction.');
assertOpenTrustlinesSame($source, $Transaction->getSourceAccount()->getAccountId(), 'Source account must be retained.');
assertOpenTrustlinesSame('42', $Transaction->getSequenceNumber()->toString(), 'Transaction must use the next sequence number.');
assertOpenTrustlinesSame(20_000, $Transaction->getFee(), 'Maximum fee must scale with operation count.');

$operations = $Transaction->getOperations();
assertOpenTrustlinesSame(2, count($operations), 'Every requested asset must become one operation.');
assertOpenTrustlinesTrue($operations[0] instanceof ChangeTrustOperation, 'First operation must be ChangeTrust.');
assertOpenTrustlinesTrue($operations[1] instanceof ChangeTrustOperation, 'Second operation must be ChangeTrust.');
assertOpenTrustlinesSame('TEST', $operations[0]->getAsset()->getCode(), 'First asset code must survive XDR round-trip.');
assertOpenTrustlinesSame($issuer_a, $operations[0]->getAsset()->getIssuer(), 'First asset issuer must survive XDR round-trip.');
assertOpenTrustlinesSame('LONGTOKEN', $operations[1]->getAsset()->getCode(), 'Second asset code must survive XDR round-trip.');
assertOpenTrustlinesSame($issuer_b, $operations[1]->getAsset()->getIssuer(), 'Second asset issuer must survive XDR round-trip.');
assertOpenTrustlinesSame('922337203685.4775807', $operations[0]->getLimit(), 'Opening a trustline must use the Stellar maximum limit.');

assertOpenTrustlinesSame(
    '0.0020000',
    OpenTrustlinesTransactionBuilder::maxFeeXlm(2),
    'Maximum fee must use 10000 stroops per operation.',
);
assertOpenTrustlinesThrows(
    static fn () => $Builder->build(openTrustlinesSourceAccount($source), []),
    'An empty trustline transaction must be rejected.',
);
assertOpenTrustlinesThrows(
    static fn () => $Builder->build(openTrustlinesSourceAccount($source), [$AssetA, $AssetA]),
    'Duplicate trustline assets must be rejected.',
);
assertOpenTrustlinesThrows(
    static fn () => $Builder->build(openTrustlinesSourceAccount($source), [Asset::native()]),
    'Native XLM must not be accepted as a trustline asset.',
);
assertOpenTrustlinesThrows(
    static fn () => $Builder->build(
        openTrustlinesSourceAccount($source),
        array_fill(0, OpenTrustlinesTransactionBuilder::MAX_OPERATIONS + 1, $AssetA),
    ),
    'A transaction with more than 100 trustline operations must be rejected.',
);

fwrite(STDOUT, "Open trustlines transaction builder tests passed.\n");
