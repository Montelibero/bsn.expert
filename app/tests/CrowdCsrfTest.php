<?php
declare(strict_types=1);

use Montelibero\BSN\Controllers\CrowdController;
use Montelibero\BSN\RequestSession;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertCrowdCsrf(mixed $expected, mixed $actual, string $message): void
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
$ControllerReflection = new ReflectionClass(CrowdController::class);
/** @var CrowdController $Controller */
$Controller = $ControllerReflection->newInstanceWithoutConstructor();
$SessionProperty = $ControllerReflection->getProperty('RequestSession');
$SessionProperty->setValue($Controller, new RequestSession(false));

$TokenMethod = $ControllerReflection->getMethod('csrfToken');
$ValidateMethod = $ControllerReflection->getMethod('isCsrfTokenValid');
$token = $TokenMethod->invoke($Controller);

assertCrowdCsrf(1, preg_match('/^[a-f0-9]{64}$/D', $token), 'The CSRF token must contain 256 random bits.');
assertCrowdCsrf($token, $TokenMethod->invoke($Controller), 'The CSRF token must remain stable in one session.');
assertCrowdCsrf(true, $ValidateMethod->invoke($Controller, $token), 'The session CSRF token must validate.');
assertCrowdCsrf(false, $ValidateMethod->invoke($Controller, str_repeat('0', 64)), 'A different CSRF token must fail.');
assertCrowdCsrf(false, $ValidateMethod->invoke($Controller, null), 'A missing CSRF token must fail.');

$RouterSource = file_get_contents(dirname(__DIR__) . '/classes/Montelibero/BSN/Routes/CrowdRouter.php');
assertCrowdCsrf(
    true,
    is_string($RouterSource) && str_contains($RouterSource, "SimpleRouter::post('/{code}/action/{action}'"),
    'Crowd action routes must only be registered as POST actions.'
);

fwrite(STDOUT, "Crowd CSRF regression tests passed.\n");
