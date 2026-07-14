<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\LoginController;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Account;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

final class MissingAccountStellarSDK extends StellarSDK
{
    public int $requestAttempts = 0;

    public function __construct()
    {
        parent::__construct('http://127.0.0.1');
    }

    public function requestAccount(string $accountId): AccountResponse
    {
        $this->requestAttempts++;
        throw HorizonRequestException::fromOtherException(
            'https://horizon.stellar.org/accounts/' . $accountId,
            'GET',
            new RuntimeException('Resource Missing'),
            new Response(404)
        );
    }
}

function assertUnfundedLogin(mixed $expected, mixed $actual, string $message): void
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

$Keypair = KeyPair::random();
$Account = new Account($Keypair->getAccountId(), new BigInteger(0));
$Builder = new TransactionBuilder($Account);
$Builder->addOperation(
    (new ManageDataOperationBuilder('bsn.expert', 'unfunded-account-test'))->build()
);
$Transaction = $Builder->build();
$Transaction->sign($Keypair, Network::public());

$ControllerReflection = new ReflectionClass(LoginController::class);
/** @var LoginController $Controller */
$Controller = $ControllerReflection->newInstanceWithoutConstructor();
$Stellar = new MissingAccountStellarSDK();

$StellarProperty = $ControllerReflection->getProperty('Stellar');
$StellarProperty->setValue($Controller, $Stellar);

$BsnReflection = new ReflectionClass(BSN::class);
$BsnProperty = $ControllerReflection->getProperty('BSN');
$BsnProperty->setValue($Controller, $BsnReflection->newInstanceWithoutConstructor());

assertUnfundedLogin(
    true,
    $Controller->checkSignature($Transaction->toEnvelopeXdrBase64()),
    'A valid signature by an unfunded account key must authenticate.'
);
assertUnfundedLogin(1, $Stellar->requestAttempts, 'A definitive Horizon 404 must not be retried.');

$UnsignedBuilder = new TransactionBuilder($Account);
$UnsignedBuilder->addOperation(
    (new ManageDataOperationBuilder('bsn.expert', 'unfunded-account-test'))->build()
);
assertUnfundedLogin(
    false,
    $Controller->checkSignature($UnsignedBuilder->build()->toEnvelopeXdrBase64()),
    'An unfunded account without its signature must not authenticate.'
);

fwrite(STDOUT, "Unfunded account login regression tests passed.\n");
