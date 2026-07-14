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
use Soneso\StellarSDK\TimeBounds;
use Soneso\StellarSDK\TransactionBuilder;

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

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

final class InMemoryMemcached extends Memcached
{
    /** @var array<string, mixed> */
    public array $values;

    /** @var list<array{key: string, value: mixed, expiration: int}> */
    public array $writes = [];

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        return $this->values[$key] ?? false;
    }

    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        $this->values[$key] = $value;
        $this->writes[] = compact('key', 'value', 'expiration');
        return true;
    }
}

final class UnavailableStellarSDK extends StellarSDK
{
    public int $request_attempts = 0;

    public function __construct()
    {
        parent::__construct('http://127.0.0.1');
    }

    public function requestAccount(string $accountId): AccountResponse
    {
        $this->request_attempts++;
        throw new RuntimeException('Horizon is unavailable');
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
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

$nonce = bin2hex(random_bytes(16));
$ServerKeypair = KeyPair::random();
$_ENV['SERVER_STELLAR_SECRET_KEY'] = $ServerKeypair->getSecretSeed();
$TimeBounds = new TimeBounds(new DateTime('-1 minute'), new DateTime('+5 minutes'));

$ServerAccount = new Account($ServerKeypair->getAccountId(), new BigInteger(0));
$ChallengeBuilder = new TransactionBuilder($ServerAccount);
$ChallengeBuilder->addOperation(
    (new ManageDataOperationBuilder('bsn.expert', $nonce))->build()
);
$ChallengeBuilder->addOperation(
    (new ManageDataOperationBuilder('web_auth_domain', 'bsn.expert'))->build()
);
$ChallengeBuilder->setTimeBounds($TimeBounds);
$ChallengeTransaction = $ChallengeBuilder->build();
$ChallengeTransaction->sign($ServerKeypair, Network::public());

$Keypair = KeyPair::random();
$Account = new Account($Keypair->getAccountId(), new BigInteger(100));
$TransactionBuilder = new TransactionBuilder($Account);
$TransactionBuilder->addOperation(
    (new ManageDataOperationBuilder('bsn.expert', $nonce))->build()
);
$TransactionBuilder->addOperation(
    (new ManageDataOperationBuilder('web_auth_domain', 'bsn.expert'))->build()
);
$TransactionBuilder->setTimeBounds($TimeBounds);
$xdr = $TransactionBuilder->build()->toEnvelopeXdrBase64();

$challenge = [
    'status' => 'created',
    'timestamp' => time(),
    'return_to' => '/',
    'challenge_xdr' => $ChallengeTransaction->toEnvelopeXdrBase64(),
    'challenge_mode' => 'sep07',
];
$Memcached = new InMemoryMemcached(['login_nonce_' . $nonce => $challenge]);
$Stellar = new UnavailableStellarSDK();

$ControllerReflection = new ReflectionClass(LoginController::class);
/** @var LoginController $Controller */
$Controller = $ControllerReflection->newInstanceWithoutConstructor();

foreach (['Memcached' => $Memcached, 'Stellar' => $Stellar] as $property_name => $value) {
    $Property = $ControllerReflection->getProperty($property_name);
    $Property->setValue($Controller, $value);
}
$BsnReflection = new ReflectionClass(BSN::class);
$BsnProperty = $ControllerReflection->getProperty('BSN');
$BsnProperty->setValue($Controller, $BsnReflection->newInstanceWithoutConstructor());

$_POST = ['xdr' => $xdr];
http_response_code(200);
$response = $Controller->Callback();

assertSameValue('Stellar node error', $response, 'Callback must expose a retryable upstream error.');
assertSameValue(503, http_response_code(), 'Callback must fail closed with HTTP 503.');
assertSameValue(4, $Stellar->request_attempts, 'Signature verification must stop after four Horizon attempts.');
assertSameValue($challenge, $Memcached->values['login_nonce_' . $nonce], 'Challenge must remain reusable after an upstream outage.');
assertSameValue([], $Memcached->writes, 'Upstream failure must not mark the challenge as successful or invalid.');

ob_end_clean();
fwrite(STDOUT, "Login fail-closed regression test passed.\n");
