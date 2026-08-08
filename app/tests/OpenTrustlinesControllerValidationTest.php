<?php

declare(strict_types=1);

use Montelibero\BSN\Controllers\OpenTrustlinesController;
use Soneso\StellarSDK\Crypto\KeyPair;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertOpenTrustlinesValidationSame(mixed $expected, mixed $actual, string $message): void
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

$issuer = KeyPair::random()->getAccountId();
$Reflection = new ReflectionClass(OpenTrustlinesController::class);
/** @var OpenTrustlinesController $Controller */
$Controller = $Reflection->newInstanceWithoutConstructor();
$parse = Closure::bind(
    function (string $value): array {
        return $this->parseTrustlineAsset($value);
    },
    $Controller,
    OpenTrustlinesController::class,
);
if (!$parse instanceof Closure) {
    throw new RuntimeException('Trustline asset parser must be callable.');
}

$asset = $parse('TEST-' . $issuer);
assertOpenTrustlinesValidationSame('asset', $asset['kind'], 'A valid CODE-G pair must be accepted.');
assertOpenTrustlinesValidationSame('TEST-' . $issuer, $asset['key'], 'A valid token key must be retained exactly.');
assertOpenTrustlinesValidationSame('TEST', $asset['asset']->getCode(), 'A valid token code must produce the expected asset.');
assertOpenTrustlinesValidationSame($issuer, $asset['asset']->getIssuer(), 'A valid issuer must produce the expected asset.');

assertOpenTrustlinesValidationSame(
    'issuer_without_code',
    $parse($issuer)['kind'],
    'A bare issuer account must receive the dedicated missing-code error.',
);
assertOpenTrustlinesValidationSame(
    'asset_required',
    $parse('  ')['kind'],
    'An empty row must require a token.',
);
assertOpenTrustlinesValidationSame(
    'asset_invalid',
    $parse('TEST}')['kind'],
    'A token code must not accept the stray brace allowed by the legacy helper.',
);
assertOpenTrustlinesValidationSame(
    'asset_invalid',
    $parse('TEST-not-an-account')['kind'],
    'An invalid issuer must be rejected.',
);
$invalid_checksum = substr($issuer, 0, -1) . ($issuer[-1] === 'A' ? 'B' : 'A');
assertOpenTrustlinesValidationSame(
    'asset_invalid',
    $parse('TEST-' . $invalid_checksum)['kind'],
    'An issuer with an invalid checksum must be rejected.',
);

fwrite(STDOUT, "Open trustlines controller validation tests passed.\n");
