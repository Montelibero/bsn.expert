<?php

declare(strict_types=1);

use Montelibero\BSN\Controllers\ApiController;
use Montelibero\BSN\RequestSession;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertApiKeyManagement(mixed $expected, mixed $actual, string $message): void
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

$_SESSION = [];
$ControllerReflection = new ReflectionClass(ApiController::class);
/** @var ApiController $Controller */
$Controller = $ControllerReflection->newInstanceWithoutConstructor();
$SessionProperty = $ControllerReflection->getProperty('RequestSession');
$SessionProperty->setValue($Controller, new RequestSession(false));

$CsrfTokenMethod = $ControllerReflection->getMethod('csrfToken');
$ValidCsrfMethod = $ControllerReflection->getMethod('validCsrf');
$CreateNonceMethod = $ControllerReflection->getMethod('createNonce');
$ConsumeCreateNonceMethod = $ControllerReflection->getMethod('consumeCreateNonce');

$csrf_token = $CsrfTokenMethod->invoke($Controller);
assertApiKeyManagement(1, preg_match('/^[a-f0-9]{64}$/D', $csrf_token), 'The CSRF token must contain 256 random bits.');
assertApiKeyManagement(true, $ValidCsrfMethod->invoke($Controller, $csrf_token), 'The session CSRF token must validate.');
assertApiKeyManagement(false, $ValidCsrfMethod->invoke($Controller, null), 'A missing CSRF token must fail.');
assertApiKeyManagement(false, $ValidCsrfMethod->invoke($Controller, str_repeat('0', 64)), 'A different CSRF token must fail.');

$create_nonce = $CreateNonceMethod->invoke($Controller);
assertApiKeyManagement(1, preg_match('/^[a-f0-9]{64}$/D', $create_nonce), 'The create nonce must contain 256 random bits.');
assertApiKeyManagement(true, $ConsumeCreateNonceMethod->invoke($Controller, $create_nonce), 'A fresh create nonce must work once.');
assertApiKeyManagement(false, $ConsumeCreateNonceMethod->invoke($Controller, $create_nonce), 'A create nonce must not be reusable.');

$template = file_get_contents(dirname(__DIR__) . '/twig/preferences_api.twig');
assertApiKeyManagement(true, is_string($template), 'The API key template must be readable.');
assertApiKeyManagement(2, substr_count($template, 'name="csrf_token"'), 'Every API key mutation form must carry CSRF.');
assertApiKeyManagement(true, str_contains($template, 'name="create_nonce"'), 'The create form must carry a one-time nonce.');

$controller_source = file_get_contents(dirname(__DIR__) . '/classes/Montelibero/BSN/Controllers/ApiController.php');
assertApiKeyManagement(true, is_string($controller_source), 'The API controller source must be readable.');
assertApiKeyManagement(false, str_contains($controller_source, 'SESSION_API_KEY_FLASH'), 'Raw API tokens must not be placed in session flash storage.');
assertApiKeyManagement(false, str_contains($controller_source, "last_used_at'] === null"), 'Unused keys must not be revealed again.');
assertApiKeyManagement(false, str_contains($controller_source, '$stored_token'), 'Stored raw API tokens must not be used to render the key list.');

$manager_source = file_get_contents(dirname(__DIR__) . '/classes/Montelibero/BSN/ApiKeysManager.php');
assertApiKeyManagement(true, is_string($manager_source), 'The API key manager source must be readable.');
assertApiKeyManagement(false, str_contains($manager_source, 'findByKeyRaw'), 'Authentication must not fall back to raw-key lookup.');
assertApiKeyManagement(false, str_contains($manager_source, "'key' => \$doc->key"), 'Stored API key documents must not expose raw tokens.');

$caddyfile = file_get_contents(dirname(__DIR__) . '/Caddyfile');
assertApiKeyManagement(true, is_string($caddyfile), 'The Caddyfile must be readable.');
assertApiKeyManagement(true, str_contains($caddyfile, 'request>headers>Authorization hash'), 'Authorization must be logged only as a stable hash.');
assertApiKeyManagement(false, str_contains($caddyfile, 'request>headers>Authorization delete'), 'Authorization must retain a safe correlation fingerprint.');
assertApiKeyManagement(true, str_contains($caddyfile, 'request>headers>Proxy-Authorization delete'), 'Proxy-Authorization must not be logged.');

fwrite(STDOUT, "API key management security regression tests passed.\n");
