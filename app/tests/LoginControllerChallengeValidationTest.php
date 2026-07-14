<?php
declare(strict_types=1);

use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\LoginController;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TimeBounds;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\TransactionPreconditions;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists('Memcached')) {
    class Memcached
    {
        public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
        {
            return false;
        }

        public function set(string $key, mixed $value, int $expiration = 0): bool
        {
            return true;
        }
    }
}

final class ChallengeMemcached extends Memcached
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values)
    {
    }

    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        return $this->values[$key] ?? false;
    }

    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        $this->values[$key] = $value;
        return true;
    }
}

final class ChallengeStellarSDK extends StellarSDK
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

function assertChallengeValidation(mixed $expected, mixed $actual, string $message): void
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

/** @param list<array{key: string, value: string, source?: MuxedAccount}> $operations */
function makeChallengeTransaction(
    MuxedAccount $source,
    BigInteger $sequence,
    array $operations,
    TimeBounds $time_bounds,
    ?Memo $memo = null,
    int $fee = 200,
): Transaction {
    $built_operations = [];
    foreach ($operations as $operation) {
        $Operation = (new ManageDataOperationBuilder($operation['key'], $operation['value']))->build();
        $Operation->setSourceAccount($operation['source'] ?? null);
        $built_operations[] = $Operation;
    }
    $Preconditions = new TransactionPreconditions();
    $Preconditions->setTimeBounds($time_bounds);

    return new Transaction(
        $source,
        $sequence,
        $built_operations,
        $memo,
        $Preconditions,
        $fee,
    );
}

$nonce = bin2hex(random_bytes(16));
$ServerKeypair = KeyPair::random();
$ClientKeypair = KeyPair::random();
$_ENV['SERVER_STELLAR_SECRET_KEY'] = $ServerKeypair->getSecretSeed();
$TimeBounds = new TimeBounds(new DateTime('-1 minute'), new DateTime('+4 minutes'));
$Operations = [
    ['key' => 'bsn.expert', 'value' => $nonce],
    ['key' => 'web_auth_domain', 'value' => 'bsn.expert'],
];

$ControllerReflection = new ReflectionClass(LoginController::class);
/** @var LoginController $Controller */
$Controller = $ControllerReflection->newInstanceWithoutConstructor();
$BsnReflection = new ReflectionClass(BSN::class);
$BsnProperty = $ControllerReflection->getProperty('BSN');
$BsnProperty->setValue($Controller, $BsnReflection->newInstanceWithoutConstructor());
$Matches = $ControllerReflection->getMethod('challengeTransactionMatches');

$Sep07Expected = makeChallengeTransaction(
    MuxedAccount::fromAccountId($ServerKeypair->getAccountId()),
    new BigInteger(1),
    $Operations,
    $TimeBounds,
);
$Sep07Expected->sign($ServerKeypair, Network::public());
$sep07_data = [
    'challenge_xdr' => $Sep07Expected->toEnvelopeXdrBase64(),
    'challenge_mode' => 'sep07',
];

$Sep07Submitted = makeChallengeTransaction(
    MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
    new BigInteger('172803997655106598'),
    $Operations,
    $TimeBounds,
    fee: 2020202,
);
$Sep07Submitted->sign($ClientKeypair, Network::public());
assertChallengeValidation(
    true,
    $Matches->invoke($Controller, $Sep07Submitted, $sep07_data, 'sep07'),
    'SEP-07 may replace source account, sequence, fee and signatures.'
);

$mutations = [
    'an extra operation' => [...$Operations, ['key' => 'extra', 'value' => 'data']],
    'a changed challenge key' => [
        ['key' => 'not-bsn.expert', 'value' => $nonce],
        $Operations[1],
    ],
    'a changed web auth domain' => [
        $Operations[0],
        ['key' => 'web_auth_domain', 'value' => 'evil.example'],
    ],
    'an operation source account' => [
        [...$Operations[0], 'source' => MuxedAccount::fromAccountId($ClientKeypair->getAccountId())],
        $Operations[1],
    ],
];

foreach ($mutations as $description => $operations) {
    $Submitted = makeChallengeTransaction(
        MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
        new BigInteger(42),
        $operations,
        $TimeBounds,
    );
    assertChallengeValidation(
        false,
        $Matches->invoke($Controller, $Submitted, $sep07_data, 'sep07'),
        'SEP-07 validation must reject ' . $description . '.'
    );
}

$ChangedTimeBounds = makeChallengeTransaction(
    MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
    new BigInteger(42),
    $Operations,
    new TimeBounds(new DateTime('-2 minutes'), new DateTime('+4 minutes')),
);
assertChallengeValidation(
    false,
    $Matches->invoke($Controller, $ChangedTimeBounds, $sep07_data, 'sep07'),
    'SEP-07 validation must reject changed time bounds.'
);

$WithMemo = makeChallengeTransaction(
    MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
    new BigInteger(42),
    $Operations,
    $TimeBounds,
    Memo::text('injected'),
);
assertChallengeValidation(
    false,
    $Matches->invoke($Controller, $WithMemo, $sep07_data, 'sep07'),
    'SEP-07 validation must reject an injected memo.'
);

$MuxedSource = makeChallengeTransaction(
    new MuxedAccount($ClientKeypair->getAccountId(), 7),
    new BigInteger(42),
    $Operations,
    $TimeBounds,
);
assertChallengeValidation(
    false,
    $Matches->invoke($Controller, $MuxedSource, $sep07_data, 'sep07'),
    'Authentication must reject a muxed source account.'
);

$ManualExpected = makeChallengeTransaction(
    MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
    new BigInteger(1),
    $Operations,
    $TimeBounds,
);
$ManualExpected->sign($ServerKeypair, Network::public());
$manual_data = [
    'challenge_xdr' => $ManualExpected->toEnvelopeXdrBase64(),
    'challenge_mode' => 'manual',
    'expected_account_id' => $ClientKeypair->getAccountId(),
];
$ManualSubmitted = makeChallengeTransaction(
    MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
    new BigInteger(1),
    $Operations,
    $TimeBounds,
);
$ManualSubmitted->sign($ClientKeypair, Network::public());
assertChallengeValidation(
    true,
    $Matches->invoke($Controller, $ManualSubmitted, $manual_data, 'manual'),
    'Manual login may add signatures without changing the transaction body.'
);

foreach ([
    'sequence' => makeChallengeTransaction(
        MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
        new BigInteger(2),
        $Operations,
        $TimeBounds,
    ),
    'fee' => makeChallengeTransaction(
        MuxedAccount::fromAccountId($ClientKeypair->getAccountId()),
        new BigInteger(1),
        $Operations,
        $TimeBounds,
        fee: 300,
    ),
    'source account' => makeChallengeTransaction(
        MuxedAccount::fromAccountId(KeyPair::random()->getAccountId()),
        new BigInteger(1),
        $Operations,
        $TimeBounds,
    ),
] as $field => $ChangedManual) {
    assertChallengeValidation(
        false,
        $Matches->invoke($Controller, $ChangedManual, $manual_data, 'manual'),
        'Manual login must reject a changed ' . $field . '.'
    );
}

session_id('manual-login-challenge-test');
session_start();
$Build = $ControllerReflection->getMethod('buildLoginChallenge');
$BuiltManual = $Build->invoke($Controller, '/preferences', $ClientKeypair->getAccountId());
$BuiltTransaction = Transaction::fromEnvelopeBase64XdrString($BuiltManual['xdr']);
assertChallengeValidation('manual', $BuiltManual['data']['challenge_mode'], 'The shared builder must mark manual challenges.');
assertChallengeValidation(
    $ClientKeypair->getAccountId(),
    $BuiltManual['data']['expected_account_id'],
    'The manual challenge must retain the account it was issued for.'
);
assertChallengeValidation(
    $ClientKeypair->getAccountId(),
    $BuiltTransaction->getSourceAccount()->getAccountId(),
    'The manual challenge must use the account supplied by the user.'
);
assertChallengeValidation('1', $BuiltTransaction->getSequenceNumber()->toString(), 'The manual challenge must be unusable on-chain.');
assertChallengeValidation(2, count($BuiltTransaction->getOperations()), 'The manual challenge must contain only auth data operations.');
assertChallengeValidation(0, count($BuiltTransaction->getSignatures()), 'The manual challenge must contain no unrelated server signature.');
assertChallengeValidation(
    300,
    $BuiltTransaction->getTimeBounds()->getMaxTime()->getTimestamp()
        - $BuiltTransaction->getTimeBounds()->getMinTime()->getTimestamp(),
    'The manual challenge must expire after five minutes.'
);
foreach ($BuiltTransaction->getOperations() as $Operation) {
    assertChallengeValidation(true, $Operation instanceof ManageDataOperation, 'The manual challenge must not contain a payment.');
}

$BuiltSep07 = $Build->invoke($Controller, '/preferences');
$BuiltSep07Transaction = Transaction::fromEnvelopeBase64XdrString($BuiltSep07['xdr']);
assertChallengeValidation('sep07', $BuiltSep07['data']['challenge_mode'], 'The shared builder must mark SEP-07 challenges.');
assertChallengeValidation(
    $ServerKeypair->getAccountId(),
    $BuiltSep07Transaction->getSourceAccount()->getAccountId(),
    'The SEP-07 challenge must start with the replaceable server source.'
);
assertChallengeValidation(1, count($BuiltSep07Transaction->getSignatures()), 'The SEP-07 challenge must retain its server signature.');

$AccountResponse = AccountResponse::fromJson([
    'account_id' => $ClientKeypair->getAccountId(),
    'sequence' => '0',
    'thresholds' => [
        'low_threshold' => 1,
        'med_threshold' => 1,
        'high_threshold' => 1,
    ],
    'signers' => [[
        'key' => $ClientKeypair->getAccountId(),
        'type' => 'ed25519_public_key',
        'weight' => 1,
    ]],
]);
$Memcached = new ChallengeMemcached([
    'login_nonce_' . $BuiltManual['nonce'] => $BuiltManual['data'],
]);
$ControllerReflection->getProperty('Memcached')->setValue($Controller, $Memcached);
$ControllerReflection->getProperty('Stellar')->setValue($Controller, new ChallengeStellarSDK($AccountResponse));
$BuiltTransaction->sign($ClientKeypair, Network::public());
$Verify = $ControllerReflection->getMethod('verifyLoginChallenge');
$ManualResult = $Verify->invoke($Controller, $BuiltTransaction->toEnvelopeXdrBase64(), 'manual');
assertChallengeValidation('OK', $ManualResult['status'], 'The shared verifier must accept the manual challenge in its browser session.');
assertChallengeValidation(
    $ClientKeypair->getAccountId(),
    $ManualResult['account_id'],
    'The manual challenge must authenticate its transaction source.'
);

session_write_close();
session_id('foreign-manual-login-session');
session_start();
$ForeignResult = $Verify->invoke($Controller, $BuiltTransaction->toEnvelopeXdrBase64(), 'manual');
assertChallengeValidation(
    'invalid_challenge',
    $ForeignResult['status'],
    'A manual challenge must be bound to the browser session that requested it.'
);
session_destroy();

fwrite(STDOUT, "Login challenge validation regression tests passed.\n");
