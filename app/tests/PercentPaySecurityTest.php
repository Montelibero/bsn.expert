<?php

declare(strict_types=1);

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\PercentPayController;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertPercentPaySecurity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$account_id = KeyPair::random()->getAccountId();
$bad_checksum = substr_replace($account_id, $account_id[10] === 'A' ? 'B' : 'A', 10, 1);
assertPercentPaySecurity(BSN::validateStellarAccountIdFormat($account_id), 'A valid Stellar account must be accepted.');
assertPercentPaySecurity(!BSN::validateStellarAccountIdFormat($bad_checksum), 'An invalid checksum must be rejected.');
assertPercentPaySecurity(!BSN::validateStellarAccountIdFormat('G' . str_repeat('A', 54) . '<'), 'Invalid characters must be rejected.');
assertPercentPaySecurity(!BSN::validateTokenNameFormat('PAY}'), 'An invalid token code suffix must be rejected.');

$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');
$Twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/twig'));
$Twig->addExtension(new TranslationExtension($Translator));
$Twig->addFunction(new TwigFunction('asset_url', static fn (string $path): string => $path));
$Twig->addGlobal('current_user', ['currentAccountId' => null]);
$Twig->addGlobal('current_contacts', new class {
    public function displayName(array $data): ?string { return null; }
});
$Twig->addGlobal('server', ['REQUEST_URI' => '/tools/percent_pay']);

$BSN = new class extends BSN {
    public function __construct() {}
};
$Controller = new PercentPayController($BSN, $Twig, new StellarSDK('https://horizon.test'), new Container());
$_SERVER['QUERY_STRING'] = 'payment_token=invalid';

$_GET = ['payment_token' => '<svg onload=alert(1)>-' . $account_id];
$html = $Controller->PercentPay();
assertPercentPaySecurity(
    preg_match('/<select\s+id="payment_token"/', $html) === 1,
    'An invalid payment token code must fall back to the standard selection.',
);

$_GET = ['payment_token' => 'PAY-<svg onload=alert(1)>'];
$html = $Controller->PercentPay();
assertPercentPaySecurity(
    preg_match('/<select\s+id="payment_token"/', $html) === 1,
    'An invalid payment token issuer must fall back to the standard selection.',
);

$_GET = ['payment_token' => 'PAY-' . $bad_checksum];
$html = $Controller->PercentPay();
assertPercentPaySecurity(
    preg_match('/<select\s+id="payment_token"/', $html) === 1,
    'A payment token issuer with an invalid checksum must fall back to the standard selection.',
);

$_GET = ['payment_token' => 'PAY-' . $account_id, 'asset_code' => 'T2'];
$html = $Controller->PercentPay();
assertPercentPaySecurity(str_contains($html, 'value="PAY-' . $account_id . '"'), 'A valid custom payment token must remain usable.');
assertPercentPaySecurity(str_contains($html, 'value="T2"'), 'An alphanumeric asset code must remain usable.');

$_GET = ['asset_code' => 'T2<svg'];
$html = $Controller->PercentPay();
assertPercentPaySecurity(!str_contains($html, 'value="T2&lt;svg"'), 'An invalid asset code must be discarded.');

$html = $Twig->render('tools_percent_pay.twig', [
    'asset_code' => '<svg onload=alert(1)>',
    'asset_issuer' => '<svg onload=alert(1)>',
    'payment_token' => ['code' => '<svg onload=alert(1)>', 'issuer' => '<svg onload=alert(1)>'],
    'accounts' => [[
        'id' => $account_id,
        'display_name' => 'Holder',
        'balance' => '1',
        'to_pay' => '0.01',
        'has_payment_trustline' => false,
    ]],
]);
assertPercentPaySecurity(!str_contains($html, '<svg onload=alert(1)>'), 'The calculation text must escape injected HTML.');
assertPercentPaySecurity(str_contains($html, '&lt;svg onload=alert(1)&gt;'), 'The calculation must still display the escaped value.');
assertPercentPaySecurity(str_contains($html, '<a href="/tokens/'), 'Translated token links must remain HTML links.');

fwrite(STDOUT, "Percent pay security tests passed.\n");
