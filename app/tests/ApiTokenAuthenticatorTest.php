<?php

declare(strict_types=1);

use Montelibero\BSN\ApiAuthenticationException;
use Montelibero\BSN\ApiKeysManager;
use Montelibero\BSN\ApiTokenAuthenticator;
use Montelibero\BSN\ApiTokenDigest;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertApiToken(mixed $expected, mixed $actual, string $message): void
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

function expectApiAuthenticationError(
    ApiTokenAuthenticator $Authenticator,
    ?string $header,
    string $expected_message,
): void {
    try {
        $Authenticator->authenticate($header, '192.0.2.10');
    } catch (ApiAuthenticationException $Exception) {
        assertApiToken($expected_message, $Exception->getMessage(), 'Authentication error message must be stable.');
        return;
    }

    throw new RuntimeException('Expected API authentication to fail.');
}

final class FakeApiKeysManager extends ApiKeysManager
{
    public array $lookups = [];
    public array $usage_updates = [];

    public function __construct(
        private readonly ?array $result,
    ) {
    }

    public function findByKey(string $key): ?array
    {
        $this->lookups[] = $key;
        return $this->result;
    }

    public function markUsed(string $id, string $ip): void
    {
        $this->usage_updates[] = [$id, $ip];
    }
}

$token = str_repeat('a', 48);
$expected_digest = hash('sha256', $token);
assertApiToken($expected_digest, ApiTokenDigest::fromToken($token), 'The stored digest must be SHA-256 of the raw token.');
assertApiToken(true, ApiTokenDigest::isValid($expected_digest), 'A lowercase SHA-256 digest must validate.');
assertApiToken(false, ApiTokenDigest::isValid(strtoupper($expected_digest)), 'Digest storage must use one canonical lowercase form.');
assertApiToken(false, ApiTokenDigest::isValid($token), 'A token is not itself a digest.');

$MissingManager = new FakeApiKeysManager(null);
$MissingAuthenticator = new ApiTokenAuthenticator($MissingManager);
expectApiAuthenticationError($MissingAuthenticator, null, ApiAuthenticationException::MISSING_TOKEN);
expectApiAuthenticationError($MissingAuthenticator, '', ApiAuthenticationException::MISSING_TOKEN);
expectApiAuthenticationError($MissingAuthenticator, 'Basic credentials', ApiAuthenticationException::MISSING_TOKEN);
expectApiAuthenticationError($MissingAuthenticator, 'Bearer ', ApiAuthenticationException::MISSING_TOKEN);
expectApiAuthenticationError($MissingAuthenticator, 'Bearer bad token', ApiAuthenticationException::INVALID_TOKEN);
expectApiAuthenticationError($MissingAuthenticator, 'Bearer unknown', ApiAuthenticationException::INVALID_TOKEN);
assertApiToken(['unknown'], $MissingManager->lookups, 'Only a syntactically usable unknown token may reach storage lookup.');
assertApiToken([], $MissingManager->usage_updates, 'Failed authentication must not update usage metadata.');

$key = [
    'id' => '507f1f77bcf86cd799439011',
    'account_id' => 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF',
    'key' => $token,
    'permissions' => ['contacts' => ['read' => true]],
];
$ValidManager = new FakeApiKeysManager($key);
$Authenticator = new ApiTokenAuthenticator($ValidManager);
$Principal = $Authenticator->authenticate('bearer ' . $token, '198.51.100.7');
$authenticated_key = $Principal->details();

assertApiToken([$token], $ValidManager->lookups, 'The raw token must only be passed to the key store lookup.');
assertApiToken(
    [['507f1f77bcf86cd799439011', '198.51.100.7']],
    $ValidManager->usage_updates,
    'Successful authentication must update usage metadata by key id.'
);
assertApiToken(false, array_key_exists('key', $authenticated_key), 'The authenticated principal must not expose the raw token.');
assertApiToken('aaaaaa…aaaa', $authenticated_key['key_masked'], 'The authenticated principal may expose only a mask.');
assertApiToken($key['account_id'], $Principal->accountId(), 'The API principal must retain the authenticated account identity.');
assertApiToken($key['id'], $Principal->keyId(), 'The API principal must retain the API key identity.');
assertApiToken('short', ApiTokenAuthenticator::maskToken('short'), 'Short legacy tokens must remain recognizable.');

fwrite(STDOUT, "API token authentication regression tests passed.\n");
