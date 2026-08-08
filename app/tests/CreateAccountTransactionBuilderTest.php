<?php

declare(strict_types=1);

use Montelibero\BSN\CreateAccountTransactionBuilder;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\BeginSponsoringFutureReservesOperation;
use Soneso\StellarSDK\ChangeTrustOperation;
use Soneso\StellarSDK\CreateAccountOperation;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\EndSponsoringFutureReservesOperation;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\SetOptionsOperation;
use Soneso\StellarSDK\Transaction;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertCreateAccountSame(mixed $expected, mixed $actual, string $message): void
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

function assertCreateAccountTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertCreateAccountThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function createAccountSource(string $account_id, string $sequence = '41'): AccountResponse
{
    return AccountResponse::fromJson([
        'account_id' => $account_id,
        'sequence' => $sequence,
    ]);
}

function createAccountOperationSource(object $Operation, string $transaction_source): string
{
    return $Operation->getSourceAccount()?->getAccountId() ?? $transaction_source;
}

$source = KeyPair::random()->getAccountId();
$destination = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$Asset = Asset::createNonNativeAsset('TEST', $issuer);
$Builder = new CreateAccountTransactionBuilder();

$xdr = $Builder->build(
    createAccountSource($source),
    $destination,
    '0',
    [$Asset],
    true,
    'OwnershipFull2',
    true,
);
$Transaction = AbstractTransaction::fromEnvelopeBase64XdrString($xdr);
assertCreateAccountTrue($Transaction instanceof Transaction, 'Builder must produce a regular Stellar transaction.');
assertCreateAccountSame($source, $Transaction->getSourceAccount()->getAccountId(), 'Transaction source must be retained.');
assertCreateAccountSame('42', $Transaction->getSequenceNumber()->toString(), 'Transaction must use the next source sequence.');
assertCreateAccountSame(80_000, $Transaction->getFee(), 'Maximum fee must cover every generated operation.');

$operations = $Transaction->getOperations();
assertCreateAccountSame(8, count($operations), 'Sponsored full setup with one trustline and lock must have eight operations.');
assertCreateAccountTrue($operations[0] instanceof BeginSponsoringFutureReservesOperation, 'First operation must begin sponsorship.');
assertCreateAccountSame($source, createAccountOperationSource($operations[0], $source), 'Sponsorship must be sourced by the current account.');
assertCreateAccountSame($destination, $operations[0]->getSponsoredId(), 'Sponsorship target must be the new account.');

assertCreateAccountTrue($operations[1] instanceof CreateAccountOperation, 'Second operation must create the account.');
assertCreateAccountSame($source, createAccountOperationSource($operations[1], $source), 'Create account must use the transaction source.');
assertCreateAccountSame($destination, $operations[1]->getDestination(), 'Create account destination must be retained.');
assertCreateAccountSame('0.0000000', $operations[1]->getStartingBalance(), 'Sponsored account creation must allow zero balance.');

assertCreateAccountTrue($operations[2] instanceof ChangeTrustOperation, 'Third operation must open the trustline.');
assertCreateAccountSame($destination, createAccountOperationSource($operations[2], $source), 'Trustline must be sourced by the new account.');
assertCreateAccountSame('TEST', $operations[2]->getAsset()->getCode(), 'Trustline asset code must be retained.');
assertCreateAccountSame($issuer, $operations[2]->getAsset()->getIssuer(), 'Trustline asset issuer must be retained.');

assertCreateAccountTrue($operations[3] instanceof ManageDataOperation, 'Fourth operation must add the child ownership tag.');
assertCreateAccountSame($destination, createAccountOperationSource($operations[3], $source), 'Owner tag must be sourced by the new account.');
assertCreateAccountSame('Owner', $operations[3]->getKey(), 'Child ownership tag must use the single Owner key.');
assertCreateAccountSame($source, $operations[3]->getValue(), 'Child Owner tag must reference the current account.');

assertCreateAccountTrue($operations[4] instanceof SetOptionsOperation, 'Fifth operation must add the current account signer.');
assertCreateAccountSame($destination, createAccountOperationSource($operations[4], $source), 'Signer addition must be sourced by the new account.');
assertCreateAccountSame(1, $operations[4]->getSignerWeight(), 'Current account signer must have weight one.');
assertCreateAccountSame(KeyPair::fromAccountId($source)->getPublicKey(), $operations[4]->getSignerKey()?->getEd25519(), 'Signer must be the current account G key.');

assertCreateAccountTrue($operations[5] instanceof EndSponsoringFutureReservesOperation, 'Sixth operation must end sponsorship.');
assertCreateAccountSame($destination, createAccountOperationSource($operations[5], $source), 'Sponsorship must be ended by the new account.');

assertCreateAccountTrue($operations[6] instanceof ManageDataOperation, 'Seventh operation must add the owner-side tag.');
assertCreateAccountSame($source, createAccountOperationSource($operations[6], $source), 'OwnershipFull tag must be sourced by the current account.');
assertCreateAccountSame('OwnershipFull2', $operations[6]->getKey(), 'OwnershipFull must retain its numeric suffix.');
assertCreateAccountSame($destination, $operations[6]->getValue(), 'OwnershipFull tag must reference the new account.');

assertCreateAccountTrue($operations[7] instanceof SetOptionsOperation, 'Final operation must lock the new account master key.');
assertCreateAccountSame($destination, createAccountOperationSource($operations[7], $source), 'Master-key lock must be sourced by the new account.');
assertCreateAccountSame(0, $operations[7]->getMasterKeyWeight(), 'Master key weight must be zero.');
assertCreateAccountSame(1, $operations[7]->getLowThreshold(), 'Low threshold must require the added signer.');
assertCreateAccountSame(1, $operations[7]->getMediumThreshold(), 'Medium threshold must require the added signer.');
assertCreateAccountSame(1, $operations[7]->getHighThreshold(), 'High threshold must require the added signer.');

$simple_xdr = $Builder->build(
    createAccountSource($source),
    $destination,
    '1.25',
    [],
    false,
    null,
    false,
);
$SimpleTransaction = AbstractTransaction::fromEnvelopeBase64XdrString($simple_xdr);
assertCreateAccountTrue($SimpleTransaction instanceof Transaction, 'Simple account creation must produce a regular transaction.');
assertCreateAccountSame(1, count($SimpleTransaction->getOperations()), 'Simple account creation must contain only CreateAccount.');
assertCreateAccountSame(10_000, $SimpleTransaction->getFee(), 'Simple account creation must have one operation fee.');
assertCreateAccountSame('1.2500000', $SimpleTransaction->getOperations()[0]->getStartingBalance(), 'Starting balance must be normalized.');

$locked_xdr = $Builder->build(
    createAccountSource($source),
    $destination,
    '1',
    [],
    false,
    null,
    true,
);
$LockedTransaction = AbstractTransaction::fromEnvelopeBase64XdrString($locked_xdr);
assertCreateAccountSame(2, count($LockedTransaction->getOperations()), 'Without sponsorship, locking must use one SetOptions operation.');
$LockOperation = $LockedTransaction->getOperations()[1];
assertCreateAccountTrue($LockOperation instanceof SetOptionsOperation, 'The second operation must configure the lock.');
assertCreateAccountSame(1, $LockOperation->getSignerWeight(), 'The lock operation must add the current account signer.');
assertCreateAccountSame(0, $LockOperation->getMasterKeyWeight(), 'The lock operation must disable the master key.');

foreach ([
    '0' => '0.0000000',
    '1.' => '1.0000000',
    '1,25' => '1.2500000',
    CreateAccountTransactionBuilder::MAX_AMOUNT => CreateAccountTransactionBuilder::MAX_AMOUNT,
] as $input => $expected) {
    assertCreateAccountSame($expected, CreateAccountTransactionBuilder::normalizeStartingBalance($input), 'Valid starting balance must be normalized.');
}
foreach (['', '-1', '.5', '1e2', '1.00000001', '922337203685.4775808'] as $input) {
    assertCreateAccountSame(null, CreateAccountTransactionBuilder::normalizeStartingBalance($input), 'Invalid starting balance must be rejected.');
}
assertCreateAccountSame(2, CreateAccountTransactionBuilder::newAccountReserveEntries(0, false, false), 'A bare account needs two reserve entries.');
assertCreateAccountSame(6, CreateAccountTransactionBuilder::newAccountReserveEntries(2, true, true), 'Trustlines, owner tag, and signer must each add one child reserve entry.');
assertCreateAccountSame(8, CreateAccountTransactionBuilder::operationCount(1, true, true, true), 'All requested setup operations must be counted.');
assertCreateAccountSame(2, CreateAccountTransactionBuilder::operationCount(0, false, false, true), 'An unsponsored lock must add one operation.');
assertCreateAccountSame(false, CreateAccountTransactionBuilder::requiresNewAccountSignature(0, false, false, false), 'A plain CreateAccount only needs the current account authorization.');
assertCreateAccountSame(true, CreateAccountTransactionBuilder::requiresNewAccountSignature(0, true, false, false), 'End sponsorship must be authorized by the new account.');
assertCreateAccountSame(true, CreateAccountTransactionBuilder::requiresNewAccountSignature(1, false, false, false), 'Child trustline operations must be authorized by the new account.');
assertCreateAccountSame('0.0080000', CreateAccountTransactionBuilder::maxFeeXlm(8), 'Fee must use 10,000 stroops per operation.');
assertCreateAccountThrows(
    static fn () => $Builder->build(createAccountSource($source), $destination, '0', [], false, 'OwnershipFull', false),
    'OwnershipFull must have a positive numeric suffix.',
);

fwrite(STDOUT, "Create account transaction builder tests passed.\n");
