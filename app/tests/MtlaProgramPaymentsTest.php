<?php

declare(strict_types=1);

use Montelibero\BSN\AssetVersions;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\Controllers\SignController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\MTLA\MtlaProgramReportService;
use Montelibero\BSN\PaymentTransactionBuilder;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarTomlImageManager;
use Montelibero\BSN\TwigExtension;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertProgramPayment(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = KeyPair::random()->getAccountId();
$first = KeyPair::random()->getAccountId();
$second = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$other_issuer = KeyPair::random()->getAccountId();
$token = ['code' => 'LABR', 'issuer' => $issuer];
$credit = static fn (string $code, string $balance = '0', array $overrides = []): array => $overrides + [
    'asset_type' => 'credit_alphanum4', 'asset_code' => $code, 'asset_issuer' => $issuer,
    'balance' => $balance, 'selling_liabilities' => '0', 'buying_liabilities' => '0',
    'limit' => '1000', 'is_authorized' => true,
];
$account = static fn (string $id, array $balances): array => [
    'account_id' => $id, 'sequence' => '41', 'balances' => $balances,
];
$SDK = new class extends StellarSDK {
    public array $accounts = [];
    public function __construct() {}
    public function requestAccount(string $accountId): AccountResponse
    {
        return AccountResponse::fromJson($this->accounts[$accountId]);
    }
};
$source_data = $account($source, [$credit('LABR', '10', ['selling_liabilities' => '2']), $credit('HOUR', '5')]);
$SDK->accounts = [
    $source => $source_data,
    $first => $account($first, [$credit('LABR'), $credit('HOUR')]),
    $second => $account($second, [$credit('LABR')]),
];
$User = new class($source) extends CurrentUser {
    public bool $authorized = false;
    public function __construct(public ?string $selected) {}
    public function isAuthorized(): bool { return $this->authorized; }
    public function getCurrentAccountId(): ?string { return $this->selected; }
};
$Sign = new class extends SignController {
    public ?string $description = null;
    public function __construct() {}
    public function SignTransaction(?string $xdr = null, ?string $uri = null, ?string $description = null, ?string $signing_title = null): ?string
    {
        $this->description = $description;
        return $xdr;
    }
};
$Session = new RequestSession(false);
$_SESSION = [];
$csrf = $Session->getOrCreateToken('csrf:mtla_program_payments:' . $source);
$Reflection = new ReflectionClass(MtlaController::class);
$Controller = $Reflection->newInstanceWithoutConstructor();
foreach (['CurrentUser' => $User, 'Stellar' => $SDK, 'RequestSession' => $Session,
    'SignController' => $Sign, 'PaymentTransactionBuilder' => new PaymentTransactionBuilder()] as $name => $value) {
    $Reflection->getProperty($name)->setValue($Controller, $value);
}
$program = ['data' => ['id' => $source, 'name' => 'Program'], 'coordinator' => ['id' => $first]];
$participants = [
    ['account' => ['id' => $first, 'display_name' => 'First'], 'timetoken' => ['code' => 'HOUR', 'issuer' => $issuer]],
    ['account' => ['id' => $second, 'display_name' => 'Second'], 'timetoken' => []],
];
$Build = $Reflection->getMethod('buildProgramPaymentAction');
$send = static function (array $tt, array $labr, ?array $rows = null) use ($Build, $Controller, $program, $participants, $token, $csrf): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['action' => 'program_payments', 'csrf_token' => $csrf, 'send_amounts' => $tt, 'send_labr_amounts' => $labr];
    return $Build->invoke($Controller, $program, $rows ?? $participants, $token);
};
$has_error = static fn (array $result, string $code): bool => $result['signing_form'] === null
    && in_array('mtla_program_page.payments.errors.' . $code, array_column($result['errors'], 'message'), true);

$mixed = $send([$first => '1,5'], [$first => '3', $second => '5']);
assertProgramPayment($mixed['errors'] === [], 'Mixed payments at the exact free LABR balance must succeed.');
$Transaction = AbstractTransaction::fromEnvelopeBase64XdrString($mixed['signing_form']);
assertProgramPayment($Transaction->getSourceAccount()->getAccountId() === $source, 'Payments must be sourced by the program.');
assertProgramPayment($Transaction->getMemo()->valueAsString() === 'Time Tokens Distribution', 'The memo must remain unchanged.');
assertProgramPayment($Sign->description === 'Time Tokens Distribution', 'The multisig description must match the memo.');
$operations = $Transaction->getOperations();
assertProgramPayment(count($operations) === 3, 'Each nonempty field must produce a payment.');
foreach ([[$first, 'HOUR', '1.5000000'], [$first, 'LABR', '3.0000000'], [$second, 'LABR', '5.0000000']] as $i => [$recipient, $code, $amount]) {
    assertProgramPayment($operations[$i]->getDestination()->getAccountId() === $recipient, 'Payments must go to the participant.');
    assertProgramPayment($operations[$i]->getAsset()->getCode() === $code && $operations[$i]->getAsset()->getIssuer() === $issuer, 'Payments must use the exact asset.');
    assertProgramPayment($operations[$i]->getAmount() === $amount, 'Amounts must survive XDR serialization exactly.');
}
assertProgramPayment($send([], [$second => '1'])['errors'] === [], 'LABR-only payments must not require a described TT.');
assertProgramPayment($send([$first => '1'], [])['errors'] === [], 'Existing TT-only payments must still work.');
assertProgramPayment($has_error($send([], [$first => '5', $second => '4']), 'insufficient_balance'), 'LABR must be summed across recipients and exclude selling liabilities.');
foreach (['0', '-1', '1e2', '1.00000001', ['1']] as $invalid) {
    assertProgramPayment($has_error($send([], [$first => $invalid]), 'invalid_amount'), 'Malformed LABR amounts must not produce XDR.');
}
assertProgramPayment($has_error($send([], []), 'no_payments'), 'Empty forms must be rejected.');
$SDK->accounts[$source] = $account($source, [$credit('LABR', '100', ['asset_issuer' => $other_issuer])]);
assertProgramPayment($has_error($send([], [$first => '1']), 'insufficient_balance'), 'A different LABR issuer must not count as a program balance.');
$SDK->accounts[$source] = $account($source, [$credit('LABR', '100', ['is_authorized' => false])]);
assertProgramPayment($has_error($send([], [$first => '1']), 'insufficient_balance'), 'Frozen source tokens must not be spendable.');
$SDK->accounts[$source] = $source_data;
$SDK->accounts[$second] = $account($second, [$credit('LABR', '0', ['asset_issuer' => $other_issuer])]);
assertProgramPayment($has_error($send([], [$second => '1']), 'missing_recipient_trustline'), 'A forged POST must still require the correct LABR trustline.');
$SDK->accounts[$second] = $account($second, [$credit('LABR', '0', ['is_authorized' => false])]);
assertProgramPayment($has_error($send([], [$second => '1']), 'recipient_not_authorized'), 'Frozen recipients must be rejected.');
$SDK->accounts[$second] = $account($second, [$credit('LABR', '2', ['limit' => '3', 'buying_liabilities' => '0.5'])]);
assertProgramPayment($has_error($send([], [$second => '1']), 'recipient_limit'), 'Recipient capacity must include buying liabilities.');
assertProgramPayment($has_error($send([$first => '1'], [$first => '1'], array_fill(0, 51, $participants[0])), 'too_many_payments'), 'Both columns must count toward the operation limit.');
$_POST['csrf_token'] = '';
assertProgramPayment($has_error($Build->invoke($Controller, $program, $participants, $token), 'expired_form'), 'Invalid CSRF must not build XDR.');
$User->selected = $second;
assertProgramPayment($Build->invoke($Controller, $program, $participants, $token) === null, 'Other participants must not enter coordinator mode.');
$User->selected = null;
assertProgramPayment($Build->invoke($Controller, $program, $participants, $token) === null, 'A visitor without a selected account must not see the form.');
$User->selected = $first;
$_SERVER['REQUEST_METHOD'] = 'GET';
$form = $Build->invoke($Controller, $program, $participants, $token);
assertProgramPayment(is_array($form), 'Selecting the coordinator must show the form without authentication.');
$User->authorized = true;
assertProgramPayment(is_array($Build->invoke($Controller, $program, $participants, $token)), 'Authenticated visitors must retain the same form.');

$ServiceReflection = new ReflectionClass(MtlaProgramReportService::class);
$Service = $ServiceReflection->newInstanceWithoutConstructor();
$Tokens = new class($token) extends TokensController {
    public function __construct(private array $token) {}
    public function getKnownTokenByCode(string $code): ?array { return $code === 'LABR' ? $this->token : null; }
};
$ServiceReflection->getProperty('TokensController')->setValue($Service, $Tokens);
$snapshot = ['program_trustlines' => [$source => ['LABR-' . $issuer => ['amount' => 10.0]]]];
$labr = $Service->buildProgramLabrReport($source, $snapshot);
assertProgramPayment($labr['balance'] === 10.0, 'The public balance must reuse the program snapshot.');
foreach ([[], ['program_trustlines' => [$source => []]], ['program_trustlines' => [$source => ['LABR-' . $other_issuer => ['amount' => 100]]]]] as $missing) {
    assertProgramPayment($Service->buildProgramLabrReport($source, $missing)['balance'] === null, 'Unknown or missing LABR trustlines must display a dash.');
}
$snapshot['program_trustlines'][$source]['LABR-' . $issuer]['amount'] = 0.0;
assertProgramPayment($Service->buildProgramLabrReport($source, $snapshot)['balance'] === 0.0, 'An existing empty trustline must display zero.');
$BSN = (new ReflectionClass(BSN::class))->newInstanceWithoutConstructor();
$BSN->makeAccountById($first)->addBalanceRecord('LABR', 0.0);
$Images = new class extends StellarTomlImageManager {
    public function __construct() {}
    public function applyTokenImages(array &$tokens): void {}
};
$ServiceReflection->getProperty('BSN')->setValue($Service, $BSN);
$ServiceReflection->getProperty('TomlImageManager')->setValue($Service, $Images);
$program['participants'] = [['id' => $first], ['id' => $second]];
$report = $Service->buildProgramParticipantReport($program, $snapshot);
$by_id = [];
foreach ($report as $row) {
    $by_id[$row['account']['id']] = $row;
}
assertProgramPayment($by_id[$first]['has_labr_trustline'] && !$by_id[$second]['has_labr_trustline'], 'A zero balance must preserve the distinction between an existing and missing LABR trustline.');

$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');
$Twig = new Environment(new ChainLoader([
    new ArrayLoader(['page.twig' => '{% block content %}{% endblock %}', 'mtla_snapshot_box.twig' => '']),
    new FilesystemLoader(dirname(__DIR__) . '/twig'),
]));
$Twig->addExtension(new TwigExtension($Translator, new AssetVersions(dirname(__DIR__))));
$Twig->addExtension(new TranslationExtension($Translator));
$view = ['program' => $program, 'program_labr' => $labr, 'participants' => $report, 'payment_action' => $form];
$html = $Twig->render('mtla_program.twig', $view);
$Dom = new DOMDocument();
@$Dom->loadHTML($html);
$XPath = new DOMXPath($Dom);
assertProgramPayment($XPath->query('//input[@name="send_labr_amounts[' . $first . ']" and not(@disabled)]')->length === 1, 'A zero-balance LABR recipient must have an enabled input.');
assertProgramPayment($XPath->query('//input[@name="send_labr_amounts[' . $second . ']" and @disabled]')->length === 1, 'A recipient without a LABR trustline must have a disabled input.');
$view['payment_action'] = null;
$public_html = $Twig->render('mtla_program.twig', $view);
assertProgramPayment(str_contains($public_html, 'amount_container') && str_contains($public_html, 'href="/tokens/LABR"'), 'Anonymous visitors must see the shared amount renderer and token link.');
assertProgramPayment(!str_contains($public_html, 'name="send_labr_amounts['), 'Public reports must not expose coordinator inputs.');

fwrite(STDOUT, "MTLA program TT and LABR payment tests passed.\n");
