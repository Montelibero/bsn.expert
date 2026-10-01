<?php

declare(strict_types=1);

use Montelibero\BSN\Account;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\ProfileEditorController;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertProfileWebsite(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['https://example.com/path', 'http://example.com', 'HTTPS://Example.com'] as $url) {
    assertProfileWebsite(AccountsController::normalizeURL($url) !== null, 'HTTP(S) Webpage links must remain available.');
}
foreach (['javascript://example.com/alert', 'tg://resolve?domain=example', 'stellar://pay?destination=G', 'data://example.com', '//example.com', 'example.com'] as $url) {
    assertProfileWebsite(AccountsController::normalizeURL($url) === null, 'A Webpage link without an HTTP(S) scheme must be rejected.');
}

$Account = Account::fromId();
$Account->setProfile([
    'Website' => ['https://example.com', 'javascript://example.com/alert', 'tg://resolve?domain=example'],
    'DeepLink' => ['tg://resolve?domain=example', 'stellar://pay?destination=G'],
]);
assertProfileWebsite($Account->getWebsite() === ['https://example.com'], 'Unsafe on-chain Website values must not become profile links.');
assertProfileWebsite(
    $Account->getProfileItem('DeepLink') === ['tg://resolve?domain=example', 'stellar://pay?destination=G'],
    'Other profile fields must not inherit the Website scheme restriction.',
);

$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');
$ControllerReflection = new ReflectionClass(ProfileEditorController::class);
$Controller = $ControllerReflection->newInstanceWithoutConstructor();
$ControllerReflection->getProperty('Translator')->setValue($Controller, $Translator);
$validate = $ControllerReflection->getMethod('validateDesiredProfile');
$errors = $validate->invoke($Controller, [
    'Website' => ['values' => ['tg://resolve?domain=example']],
    'DeepLink' => ['values' => ['tg://resolve?domain=example', 'stellar://pay?destination=G']],
]);
assertProfileWebsite(count($errors) === 1, 'The editor must reject unsafe Website schemes without rejecting other fields.');
assertProfileWebsite(str_contains($errors[0], 'http://'), 'The editor error must explain the accepted Website schemes.');
assertProfileWebsite(
    $validate->invoke($Controller, ['Website' => ['values' => ['https://example.com']]]) === [],
    'The editor must accept an HTTPS Webpage link.',
);

fwrite(STDOUT, "Profile Website URL tests passed.\n");
