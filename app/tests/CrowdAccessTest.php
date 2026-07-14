<?php
declare(strict_types=1);

use Montelibero\BSN\CrowdAccess;
use Montelibero\BSN\CrowdConfig;
use Montelibero\BSN\CurrentUser;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

final class CrowdAccessCurrentUser extends CurrentUser
{
    public function __construct(
        private readonly ?string $accountId,
        private readonly ?string $currentAccountId = null,
    ) {
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function getCurrentAccountId(): ?string
    {
        return $this->currentAccountId ?? $this->accountId;
    }
}

final class CrowdAccessStellarSDK extends StellarSDK
{
    public int $requestAttempts = 0;

    public function __construct(
        private readonly AccountResponse|Throwable $response,
    ) {
        parent::__construct('http://127.0.0.1');
    }

    public function requestAccount(string $accountId): AccountResponse
    {
        $this->requestAttempts++;
        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}

function assertCrowdAccess(mixed $expected, mixed $actual, string $message): void
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

function crowdIssuerResponse(string $issuer, array $signers): AccountResponse
{
    return AccountResponse::fromJson([
        'account_id' => $issuer,
        'sequence' => '0',
        'thresholds' => [
            'low_threshold' => 1,
            'med_threshold' => 2,
            'high_threshold' => 2,
        ],
        'signers' => $signers,
    ]);
}

$Issuer = KeyPair::random();
$Signer = KeyPair::random();
$ZeroWeightSigner = KeyPair::random();
$NonEd25519Signer = KeyPair::random();
$Outsider = KeyPair::random();
$_ENV['CROWD_STELLAR_ACCOUNT_ID'] = $Issuer->getAccountId();
$Config = new CrowdConfig();
$IssuerResponse = crowdIssuerResponse($Issuer->getAccountId(), [
    ['key' => $Issuer->getAccountId(), 'type' => 'ed25519_public_key', 'weight' => 0],
    ['key' => $Signer->getAccountId(), 'type' => 'ed25519_public_key', 'weight' => 1],
    ['key' => $ZeroWeightSigner->getAccountId(), 'type' => 'ed25519_public_key', 'weight' => 0],
    ['key' => $NonEd25519Signer->getAccountId(), 'type' => 'preauth_tx', 'weight' => 1],
]);

$NoHorizon = new CrowdAccessStellarSDK(new RuntimeException('must not be called'));
assertCrowdAccess(
    true,
    (new CrowdAccess(new CrowdAccessCurrentUser($Issuer->getAccountId()), $Config, $NoHorizon))->canManage(),
    'The authenticated issuer account must be allowed directly.'
);
assertCrowdAccess(0, $NoHorizon->requestAttempts, 'Issuer access must not depend on a second Horizon read.');

assertCrowdAccess(
    true,
    (new CrowdAccess(new CrowdAccessCurrentUser($Signer->getAccountId()), $Config, new CrowdAccessStellarSDK($IssuerResponse)))->canManage(),
    'A positive-weight ed25519 signer must be allowed.'
);
assertCrowdAccess(
    false,
    (new CrowdAccess(new CrowdAccessCurrentUser($ZeroWeightSigner->getAccountId()), $Config, new CrowdAccessStellarSDK($IssuerResponse)))->canManage(),
    'A zero-weight signer fixture must not grant access.'
);
assertCrowdAccess(
    false,
    (new CrowdAccess(new CrowdAccessCurrentUser($NonEd25519Signer->getAccountId()), $Config, new CrowdAccessStellarSDK($IssuerResponse)))->canManage(),
    'A non-ed25519 signer must not grant interactive user access.'
);
assertCrowdAccess(
    false,
    (new CrowdAccess(
        new CrowdAccessCurrentUser($Outsider->getAccountId(), $Issuer->getAccountId()),
        $Config,
        new CrowdAccessStellarSDK($IssuerResponse)
    ))->canManage(),
    'The UI current_account value must not grant crowd access.'
);

$UpstreamFailure = new CrowdAccessStellarSDK(new RuntimeException('Horizon unavailable'));
$thrown = false;
try {
    (new CrowdAccess(new CrowdAccessCurrentUser($Signer->getAccountId()), $Config, $UpstreamFailure))->canManage();
} catch (RuntimeException) {
    $thrown = true;
}
assertCrowdAccess(true, $thrown, 'Horizon failures must propagate so mutation endpoints fail closed.');

fwrite(STDOUT, "Crowd access regression tests passed.\n");
