<?php
declare(strict_types=1);

use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\LoginController;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Account;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Xdr\XdrDecoratedSignature;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

final class AccountFixtureStellarSDK extends StellarSDK
{
    public function __construct(private readonly AccountResponse $AccountResponse)
    {
        parent::__construct('http://127.0.0.1');
    }

    public function requestAccount(string $accountId): AccountResponse
    {
        return $this->AccountResponse;
    }
}

function assertSignatureCheck(bool $expected, ?bool $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

/**
 * @param list<array{keypair: KeyPair, weight: int}> $signers
 */
function makeAccountResponse(string $account_id, int $medium_threshold, array $signers): AccountResponse
{
    return AccountResponse::fromJson([
        'account_id' => $account_id,
        'sequence' => '0',
        'thresholds' => [
            'low_threshold' => 1,
            'med_threshold' => $medium_threshold,
            'high_threshold' => $medium_threshold,
        ],
        'signers' => array_map(
            static fn(array $signer): array => [
                'key' => $signer['keypair']->getAccountId(),
                'type' => 'ed25519_public_key',
                'weight' => $signer['weight'],
            ],
            $signers
        ),
    ]);
}

/** @param list<KeyPair> $signers */
function makeSignedTransaction(KeyPair $source, array $signers): Transaction
{
    $Account = new Account($source->getAccountId(), new BigInteger(0));
    $TransactionBuilder = new TransactionBuilder($Account);
    $TransactionBuilder->addOperation(
        (new ManageDataOperationBuilder('bsn.expert', 'signature-weight-test'))->build()
    );
    $Transaction = $TransactionBuilder->build();
    foreach ($signers as $Signer) {
        $Transaction->sign($Signer, Network::public());
    }

    return $Transaction;
}

function makeController(AccountResponse $AccountResponse): LoginController
{
    $ControllerReflection = new ReflectionClass(LoginController::class);
    /** @var LoginController $Controller */
    $Controller = $ControllerReflection->newInstanceWithoutConstructor();

    $StellarProperty = $ControllerReflection->getProperty('Stellar');
    $StellarProperty->setValue($Controller, new AccountFixtureStellarSDK($AccountResponse));

    $BsnReflection = new ReflectionClass(BSN::class);
    $BsnProperty = $ControllerReflection->getProperty('BSN');
    $BsnProperty->setValue($Controller, $BsnReflection->newInstanceWithoutConstructor());

    return $Controller;
}

$SignerOne = KeyPair::random();
$SignerTwo = KeyPair::random();

$ZeroThresholdAccount = makeAccountResponse($SignerOne->getAccountId(), 0, [
    ['keypair' => $SignerOne, 'weight' => 1],
]);
assertSignatureCheck(
    false,
    makeController($ZeroThresholdAccount)->checkSignature(
        makeSignedTransaction($SignerOne, [])->toEnvelopeXdrBase64()
    ),
    'A zero-threshold account must not authenticate without a verified signer.'
);
assertSignatureCheck(
    false,
    makeController($ZeroThresholdAccount)->checkSignature(
        makeSignedTransaction($SignerOne, [$SignerTwo])->toEnvelopeXdrBase64()
    ),
    'A zero-threshold account must not authenticate with an unrelated signature.'
);
assertSignatureCheck(
    true,
    makeController($ZeroThresholdAccount)->checkSignature(
        makeSignedTransaction($SignerOne, [$SignerOne])->toEnvelopeXdrBase64()
    ),
    'A zero-threshold account must authenticate with a positive-weight signer.'
);

$ZeroWeightSignerAccount = makeAccountResponse($SignerOne->getAccountId(), 0, [
    ['keypair' => $SignerOne, 'weight' => 0],
]);
assertSignatureCheck(
    false,
    makeController($ZeroWeightSignerAccount)->checkSignature(
        makeSignedTransaction($SignerOne, [$SignerOne])->toEnvelopeXdrBase64()
    ),
    'A zero-weight signer must not authenticate a zero-threshold account.'
);

$DuplicateTransaction = makeSignedTransaction($SignerOne, [$SignerOne]);
$DuplicateSignature = $DuplicateTransaction->getSignatures()[0];
$DuplicateTransaction->addSignature(new XdrDecoratedSignature(
    $DuplicateSignature->getHint(),
    $DuplicateSignature->getSignature()
));
$SingleSignerAccount = makeAccountResponse($SignerOne->getAccountId(), 2, [
    ['keypair' => $SignerOne, 'weight' => 1],
]);
assertSignatureCheck(
    false,
    makeController($SingleSignerAccount)->checkSignature($DuplicateTransaction->toEnvelopeXdrBase64()),
    'Repeating one signature must not satisfy a multisig threshold.'
);

$DuplicateWeightedAccount = makeAccountResponse($SignerOne->getAccountId(), 2, [
    ['keypair' => $SignerOne, 'weight' => 2],
]);
assertSignatureCheck(
    false,
    makeController($DuplicateWeightedAccount)->checkSignature($DuplicateTransaction->toEnvelopeXdrBase64()),
    'An envelope containing a duplicate signature must be rejected even if that signer has enough weight.'
);

$TwoSignerTransaction = makeSignedTransaction($SignerOne, [$SignerOne, $SignerTwo]);
$TwoSignerAccount = makeAccountResponse($SignerOne->getAccountId(), 2, [
    ['keypair' => $SignerOne, 'weight' => 1],
    ['keypair' => $SignerTwo, 'weight' => 1],
]);
assertSignatureCheck(
    true,
    makeController($TwoSignerAccount)->checkSignature($TwoSignerTransaction->toEnvelopeXdrBase64()),
    'Two different signers must satisfy their combined threshold.'
);

$WeightedSignerTransaction = makeSignedTransaction($SignerOne, [$SignerOne]);
$WeightedSignerAccount = makeAccountResponse($SignerOne->getAccountId(), 2, [
    ['keypair' => $SignerOne, 'weight' => 2],
]);
assertSignatureCheck(
    true,
    makeController($WeightedSignerAccount)->checkSignature($WeightedSignerTransaction->toEnvelopeXdrBase64()),
    'One signer with sufficient weight must remain valid.'
);

$WrongHintTransaction = makeSignedTransaction($SignerOne, [$SignerOne]);
$ValidSignature = $WrongHintTransaction->getSignatures()[0];
$wrong_hint = $ValidSignature->getHint();
$wrong_hint[0] = chr(ord($wrong_hint[0]) ^ 0xff);
$WrongHintTransaction->setSignatures([
    new XdrDecoratedSignature($wrong_hint, $ValidSignature->getSignature()),
]);
assertSignatureCheck(
    false,
    makeController($WeightedSignerAccount)->checkSignature($WrongHintTransaction->toEnvelopeXdrBase64()),
    'A signature with a mismatched decorated hint must be rejected.'
);

fwrite(STDOUT, "Login signature weight regression tests passed.\n");
