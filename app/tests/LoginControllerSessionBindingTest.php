<?php
declare(strict_types=1);

use Montelibero\BSN\Controllers\LoginController;
use Montelibero\BSN\RequestSession;

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
    }
}

final class SessionBindingMemcached extends Memcached
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        return $this->values[$key] ?? false;
    }
}

function assertSessionBinding(mixed $expected, mixed $actual, string $message): void
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

function pollChallenge(LoginController $Controller, string $session_id, string $nonce): array
{
    session_id($session_id);
    $_COOKIE = [session_name() => $session_id];
    $_GET = ['format' => 'json', 'nonce' => $nonce];
    $_POST = [];
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['REQUEST_URI'] = '/login?format=json&nonce=' . rawurlencode($nonce);

    return json_decode($Controller->Login(), true, flags: JSON_THROW_ON_ERROR);
}

$owner_session_id = 'owner-session-id';
$nonce = 'session-binding-test';
$challenge = [
    'uri' => 'web+stellar:tx?xdr=test',
    'status' => 'created',
    'timestamp' => time(),
    'return_to' => '/preferences',
    'browser_session_hash' => hash('sha256', $owner_session_id),
];

$ControllerReflection = new ReflectionClass(LoginController::class);
/** @var LoginController $Controller */
$Controller = $ControllerReflection->newInstanceWithoutConstructor();
$MemcachedProperty = $ControllerReflection->getProperty('Memcached');
$MemcachedProperty->setValue($Controller, new SessionBindingMemcached([
    'login_nonce_' . $nonce => $challenge,
]));

$owner_status = pollChallenge($Controller, $owner_session_id, $nonce);
assertSessionBinding('created', $owner_status['status'] ?? null, 'The initiating browser must see its challenge.');
assertSessionBinding($challenge['timestamp'], $owner_status['timestamp'] ?? null, 'The initiating browser must receive the timer timestamp.');
assertSessionBinding(['status', 'timestamp'], array_keys($owner_status), 'Polling must not expose internal challenge data.');

$foreign_status = pollChallenge($Controller, 'foreign-session-id', $nonce);
assertSessionBinding(['status' => 'timeout'], $foreign_status, 'A foreign browser must not observe or complete the challenge.');

$legacy_nonce = 'legacy-unbound-challenge';
$LegacyController = $ControllerReflection->newInstanceWithoutConstructor();
$MemcachedProperty->setValue($LegacyController, new SessionBindingMemcached([
    'login_nonce_' . $legacy_nonce => [
        'status' => 'OK',
        'timestamp' => time(),
        'account_id' => 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF',
    ],
]));
assertSessionBinding(
    ['status' => 'timeout'],
    pollChallenge($LegacyController, $owner_session_id, $legacy_nonce),
    'A challenge without a browser binding must fail closed.'
);

session_id('');
session_save_path(sys_get_temp_dir());
session_start();
$_SESSION['sticky'] = 'preserved';
$old_session_id = session_id();
$RequestSession = new RequestSession(true);
$RequestSession->regenerateId();
assertSessionBinding(false, hash_equals($old_session_id, session_id()), 'Authentication must rotate the session ID.');
assertSessionBinding('preserved', $_SESSION['sticky'] ?? null, 'Session rotation must preserve long-lived session data.');
session_destroy();

ob_end_clean();
fwrite(STDOUT, "Login browser-session binding regression tests passed.\n");
