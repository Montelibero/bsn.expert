<?php

declare(strict_types=1);

use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\RequestArrayView;
use Montelibero\BSN\RequestSession;

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

require dirname(__DIR__) . '/vendor/autoload.php';

function assertLazySession(mixed $expected, mixed $actual, string $message): void
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

function sessionFileCount(string $directory): int
{
    return count(glob($directory . '/sess_*') ?: []);
}

$session_directory = sys_get_temp_dir() . '/bsn-lazy-session-' . bin2hex(random_bytes(8));
if (!mkdir($session_directory, 0700) && !is_dir($session_directory)) {
    throw new RuntimeException('Unable to create the temporary session directory.');
}

session_name('BSN_LAZY_TEST');
session_save_path($session_directory);
ini_set('session.use_cookies', '1');
ini_set('session.use_strict_mode', '0');

$_COOKIE = [];
$_SESSION = [];

$Session = new RequestSession(true);
$SessionView = new RequestArrayView();
$Session->onStarted(static function () use ($SessionView): void {
    $SessionView->bind($_SESSION);
});

$Session->beginRequest();
$SessionView->bind($_SESSION);
$Bsn = (new ReflectionClass(BSN::class))->newInstanceWithoutConstructor();
$CurrentUser = new CurrentUser($Bsn, $Session);
assertLazySession(false, $Session->isStarted(), 'An anonymous read must not start a PHP session.');
assertLazySession('', session_id(), 'An anonymous read must not allocate a session id.');
assertLazySession(null, $Session->get('missing'), 'Reading a missing value must stay stateless.');
assertLazySession(null, $Session->consume('missing'), 'Consuming a missing value must stay stateless.');
assertLazySession(null, $CurrentUser->getCurrentAccountSwitchKey(), 'Rendering the account switcher must not start an anonymous session.');
assertLazySession(false, $CurrentUser->isCurrentAccountSwitchKeyValid('invalid'), 'Validating an unknown switch key must fail closed.');
assertLazySession(false, $Session->isStarted(), 'Reading or validating a switch key must stay stateless.');
$Session->remove('missing');
$Session->endRequest();
assertLazySession(0, sessionFileCount($session_directory), 'A read-only request must not create session storage.');

$Session->beginRequest();
$SessionView->bind($_SESSION);
$Session->set('sticky', 'preserved');
assertLazySession(true, $Session->isStarted(), 'The first write must start a PHP session.');
assertLazySession('preserved', $SessionView['sticky'], 'Twig session view must follow a lazy session start.');
$first_session_id = session_id();
assertLazySession(true, $first_session_id !== '', 'The first write must allocate a session id.');
$first_token = $Session->getOrCreateToken('test');
assertLazySession($first_token, $Session->getOrCreateToken('test'), 'A request token must remain stable in one session.');
$switch_key = $CurrentUser->getCurrentAccountSwitchKey();
assertLazySession(true, is_string($switch_key) && $switch_key !== '', 'An established session may create its account switch key.');
assertLazySession(true, $CurrentUser->isCurrentAccountSwitchKeyValid($switch_key), 'The stored account switch key must validate.');
$Session->endRequest();
assertLazySession(1, sessionFileCount($session_directory), 'The written session must be persisted once.');

$_COOKIE = [session_name() => $first_session_id];
$Session->beginRequest();
$SessionView->bind($_SESSION);
assertLazySession(true, $Session->isStarted(), 'An existing session cookie must load its session.');
assertLazySession('preserved', $Session->get('sticky'), 'An existing session must restore its data.');
assertLazySession($first_token, $Session->getOrCreateToken('test'), 'Stored request tokens must survive another request.');
assertLazySession($switch_key, $CurrentUser->getCurrentAccountSwitchKey(), 'The account switch key must survive another request.');
$Session->endRequest();

$_COOKIE = [];
$Session->beginRequest();
$SessionView->bind($_SESSION);
assertLazySession(false, $Session->isStarted(), 'A later anonymous request must remain stateless in the same worker.');
assertLazySession('', session_id(), 'The previous visitor session id must not leak in a worker.');
assertLazySession(null, $Session->get('sticky'), 'The previous visitor data must not leak in a worker.');
$Session->endRequest();
assertLazySession(1, sessionFileCount($session_directory), 'Another anonymous read must not create storage.');

foreach (glob($session_directory . '/sess_*') ?: [] as $session_file) {
    unlink($session_file);
}
rmdir($session_directory);

ob_end_clean();
fwrite(STDOUT, "Lazy session regression tests passed.\n");
