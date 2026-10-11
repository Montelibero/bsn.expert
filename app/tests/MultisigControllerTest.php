<?php

declare(strict_types=1);

use DI\Container;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\SignController;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Requests\LedgersRequestBuilder;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\SetOptionsOperation;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertMultisigSame(mixed $expected, mixed $actual, string $message): void
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

function multisigInputValue(string $html, string $name): ?string
{
    preg_match('/<input\b[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/s', $html, $input);
    preg_match('/\bvalue="([^"]*)"/', $input[0] ?? '', $value);

    return isset($value[1]) ? html_entity_decode($value[1], ENT_QUOTES) : null;
}

$source = KeyPair::random()->getAccountId();
$authenticated = KeyPair::random()->getAccountId();
$last_signer = KeyPair::random()->getAccountId();
$new_signer = KeyPair::random()->getAccountId();
$BSN = new class extends BSN {
    public function __construct() {}
    public function makeAccountById(string $id): Account { return Account::fromId($id); }
};
$Contacts = new class extends CurrentContacts {
    public function __construct() {}
    public function serialize(Account $Account, bool $ignore_contact = false): array
    {
        return ['id' => $Account->getId(), 'display_name' => $Account->getId()];
    }
};
$Stellar = new class($source, $authenticated, $last_signer) extends StellarSDK {
    public array $requested = [];
    public int $master_weight = 5;
    public function __construct(private string $source, private string $first, private string $last) {}
    public function requestAccount(string $accountId): AccountResponse
    {
        $this->requested[] = $accountId;
        assertMultisigSame($this->source, $accountId, 'Horizon must receive the selected account.');

        return AccountResponse::fromJson([
            'account_id' => $accountId,
            'sequence' => '123',
            'thresholds' => ['low_threshold' => 1, 'med_threshold' => 2, 'high_threshold' => 3],
            'signers' => [
                ['key' => $this->source, 'weight' => $this->master_weight, 'type' => 'ed25519_public_key'],
                ['key' => $this->first, 'weight' => 2, 'type' => 'ed25519_public_key'],
                ['key' => $this->last, 'weight' => 1, 'type' => 'ed25519_public_key'],
            ],
        ]);
    }
    public function ledgers(): LedgersRequestBuilder
    {
        throw new RuntimeException('Offline fixture uses the reserve fallback.');
    }
};
$Signer = new class extends SignController {
    public ?string $xdr = null;
    public function __construct() {}
    public function SignTransaction(
        ?string $xdr = null,
        ?string $uri = null,
        ?string $description = null,
        ?string $signing_title = null,
    ): ?string {
        $this->xdr = $xdr;
        return '<div>External signing preview</div>';
    }
};
$Container = new Container();
$Container->set(SignController::class, $Signer);
$Translator = new Translator('en');
$Twig = new class(new ChainLoader([
    new ArrayLoader(['page.twig' => '{% block content %}{% endblock %}']),
    new FilesystemLoader(dirname(__DIR__) . '/twig'),
])) extends Environment {
    public array $context = [];
    public function render($name, array $context = []): string
    {
        $this->context = $context;
        return parent::render($name, $context);
    }
};
$Twig->addExtension(new TranslationExtension($Translator));
$CurrentUser = new CurrentUser($BSN, new RequestSession(false));
$Controller = new MultisigController(
    $BSN, $CurrentUser, $Contacts, $Twig, $Stellar,
    new StellarAccountReserveCalculator($Stellar), $Translator, $Container,
);

$post = [
    'current_account' => $source,
    'action' => 'tools_multisig.buttons.calculate',
    'weight_0' => '5',
    'low_threshold' => '1',
    'med_threshold' => '2',
    'high_threshold' => '3',
];
for ($i = 1; $i <= 20; $i++) {
    $post['account_' . $i] = '';
    $post['weight_' . $i] = '';
}
$post['account_1'] = $authenticated;
$post['weight_1'] = '2';
$post['account_2'] = $last_signer;
$post['weight_2'] = '1';

$render = function (array $values = [], ?string $session_account = null, bool $expects_account = true) use (
    $source, $authenticated, $CurrentUser, $Controller, $Stellar, $Signer,
): string {
    $_GET = [];
    $_POST = $values;
    $_COOKIE = [];
    $_SERVER['REQUEST_METHOD'] = $values ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = '/tools/multisig';
    $_SESSION = ['account' => ['id' => $authenticated], 'current_account_id' => $session_account ?? $source];
    $CurrentUser->beginRequest();
    $Stellar->requested = [];
    $Signer->xdr = null;
    http_response_code(200);
    assertMultisigSame($authenticated, $CurrentUser->getAccountId(), 'Authenticated identity must remain separate.');
    $html = $Controller->Multisig();
    assertMultisigSame($expects_account ? [$source] : [], $Stellar->requested, 'Valid forms must share one Horizon snapshot; invalid targets must not load any account.');
    return $html;
};
$assert_context = function (string $html) use ($source, $authenticated, $last_signer, $Twig): void {
    $data = $Twig->context;
    assertMultisigSame($source, $data['account_id'], 'Rendered account id must remain the selected account.');
    assertMultisigSame($source, $data['account']['id'], 'Summary account link must identify the selected account.');
    assertMultisigSame($source, $data['multisig'][0]['account'], 'Master-key form data must identify the selected account.');
    assertMultisigSame($source, multisigInputValue($html, 'account_0'), 'Rendered master key must identify the selected account.');
    assertMultisigSame($source, multisigInputValue($html, 'current_account'), 'Every form must pin its selected account.');
    assertMultisigSame(true, str_contains($html, 'href="/accounts/' . $source . '"'), 'Current-state account link must match the form.');
    assertMultisigSame(['low' => 1, 'med' => 2, 'high' => 3], $data['current_multisig']['thresholds'], 'Summary must retain current on-chain thresholds.');
    assertMultisigSame(5, $data['current_multisig']['master_key'], 'Summary must retain current on-chain master weight.');
    assertMultisigSame(
        [$authenticated => 2, $last_signer => 1],
        array_column($data['current_multisig']['signers'], 'weight', 'id'),
        'Summary must retain current on-chain signers.',
    );
};
$decode = function () use ($Signer, $source): array {
    if ($Signer->xdr === null) {
        throw new RuntimeException('A valid change must produce an external signing preview.');
    }
    $Transaction = AbstractTransaction::fromEnvelopeBase64XdrString($Signer->xdr);
    if (!$Transaction instanceof Transaction) {
        throw new RuntimeException('Preview must contain a normal transaction.');
    }
    assertMultisigSame($source, $Transaction->getSourceAccount()->getAccountId(), 'XDR source must be the selected account.');
    assertMultisigSame('124', $Transaction->getSequenceNumber()->toString(), 'XDR must use the selected account sequence.');
    assertMultisigSame([], $Transaction->getSignatures(), 'Preview must remain unsigned.');
    $operations = [];
    foreach ($Transaction->getOperations() as $Operation) {
        if (!$Operation instanceof SetOptionsOperation) {
            throw new RuntimeException('Only multisig options may change.');
        }
        assertMultisigSame(null, $Operation->getSourceAccount(), 'Operations must inherit the selected transaction source.');
        $operations[] = [
            'master' => $Operation->getMasterKeyWeight(),
            'low' => $Operation->getLowThreshold(),
            'med' => $Operation->getMediumThreshold(),
            'high' => $Operation->getHighThreshold(),
            'signer' => $Operation->getSignerKey() === null ? null : StrKey::encodeAccountId($Operation->getSignerKey()->getEd25519()),
            'weight' => $Operation->getSignerWeight(),
        ];
    }
    return $operations;
};
$signer_operation = static fn (string $id, int $weight): array => [
    'master' => null, 'low' => null, 'med' => null, 'high' => null, 'signer' => $id, 'weight' => $weight,
];
$tests = [];
$tests['GET pins account and renders current multisig'] = function () use ($render, $assert_context, $Signer, $Twig): void {
    $html = $render();
    assertMultisigSame([], $Twig->context['errors'], 'Initial form must not report errors.');
    assertMultisigSame(null, $Signer->xdr, 'GET must not prepare a transaction.');
    $assert_context($html);
};
$tests['POST without changes retains account'] = function () use ($render, $assert_context, $post, $Signer, $Twig): void {
    $html = $render($post);
    assertMultisigSame(['tools_multisig.errors.no_changes_to_save'], $Twig->context['errors'], 'Unchanged form must report no changes.');
    assertMultisigSame(null, $Signer->xdr, 'Unchanged form must not create an XDR.');
    $assert_context($html);
};
$tests['POST changes preserve exact XDR source and operations'] = function () use (
    $render, $assert_context, $post, $authenticated, $last_signer, $new_signer, $decode, $signer_operation, $Twig,
): void {
    $html = $render(array_replace($post, [
        'weight_0' => '4', 'low_threshold' => '2', 'med_threshold' => '3', 'high_threshold' => '4',
        'weight_1' => '3', 'account_2' => $new_signer, 'weight_2' => '2',
    ]));
    assertMultisigSame([], $Twig->context['errors'], 'Valid changes must pass validation.');
    assertMultisigSame([
        ['master' => 4, 'low' => 2, 'med' => 3, 'high' => 4, 'signer' => null, 'weight' => null],
        $signer_operation($last_signer, 0),
        $signer_operation($authenticated, 3),
        $signer_operation($new_signer, 2),
    ], $decode(), 'XDR must contain exactly the requested threshold, master and signer changes.');
    $assert_context($html);
};
$tests['POST removing all extra signers retains account'] = function () use (
    $render, $assert_context, $post, $authenticated, $last_signer, $decode, $signer_operation,
): void {
    $html = $render(array_replace($post, ['account_1' => '', 'weight_1' => '', 'account_2' => '', 'weight_2' => '']));
    assertMultisigSame([
        $signer_operation($authenticated, 0), $signer_operation($last_signer, 0),
    ], $decode(), 'Removing all extra signers must leave the master and thresholds intact.');
    $assert_context($html);
};
$tests['POST pinned form wins over another tab session'] = function () use (
    $render, $assert_context, $post, $source, $last_signer, $decode,
): void {
    $html = $render(array_replace($post, ['current_account' => $source, 'weight_0' => '4']), $last_signer);
    assertMultisigSame([
        ['master' => 4, 'low' => null, 'med' => null, 'high' => null, 'signer' => null, 'weight' => null],
    ], $decode(), 'A stale tab must still prepare changes for its original account.');
    $assert_context($html);
};
$tests['POST old form without target fails before loading any account'] = function () use ($render, $post, $last_signer, $Signer): void {
    unset($post['current_account']);
    $html = $render($post, $last_signer, false);
    assertMultisigSame(400, http_response_code(), 'An old form without a pinned target must be rejected.');
    assertMultisigSame('tools_multisig.errors.account_context_required', $html, 'Old form must explain that the editor needs to be reopened.');
    assertMultisigSame(null, $Signer->xdr, 'Old form must not prepare changes for another tab account.');
};
$tests['POST invalid target does not fall back to the session'] = function () use ($render, $post, $last_signer, $Signer): void {
    $html = $render(array_replace($post, ['current_account' => 'invalid-account']), $last_signer, false);
    assertMultisigSame(400, http_response_code(), 'An invalid pinned target must be rejected.');
    assertMultisigSame('tools_multisig.errors.account_context_required', $html, 'Invalid target must explain that the editor needs to be reopened.');
    assertMultisigSame(null, $Signer->xdr, 'Invalid target must not prepare changes for the session account.');
};
$tests['GET displays disabled master weight as zero'] = function () use ($render, $Stellar, $Twig, $source): void {
    $Stellar->master_weight = 0;
    try {
        $html = $render();
        assertMultisigSame(['account' => $source, 'weight' => 0], $Twig->context['multisig'][0], 'Disabled master must have an explicit zero weight.');
        assertMultisigSame('0', multisigInputValue($html, 'weight_0'), 'A disabled master must render zero rather than an empty weight.');
        assertMultisigSame(0, $Twig->context['current_multisig']['master_key'], 'Summary must retain the disabled master.');
    } finally {
        $Stellar->master_weight = 5;
    }
};
$tests['POST cannot remove every signing key even with zero thresholds'] = function () use ($render, $post, $Twig, $Signer): void {
    $render(array_replace($post, [
        'weight_0' => '0', 'low_threshold' => '0', 'med_threshold' => '0', 'high_threshold' => '0',
        'account_1' => '', 'weight_1' => '', 'account_2' => '', 'weight_2' => '',
    ]));
    assertMultisigSame(['tools_multisig.errors.impossible_to_collect_signers'], $Twig->context['errors'], 'Zero thresholds must not permit removal of all signing keys.');
    assertMultisigSame(null, $Signer->xdr, 'A configuration without signing keys must not reach signing.');
};
$tests['POST rejects own master as an additional signer'] = function () use ($render, $post, $source, $Twig, $Signer): void {
    $render(array_replace($post, ['account_1' => $source]));
    assertMultisigSame(true, str_contains(implode(' ', $Twig->context['errors']), 'tools_multisig.errors.doubled_signer_account'), 'Master key cannot appear again as an extra signer.');
    assertMultisigSame(null, $Signer->xdr, 'Duplicating the master must not produce a transaction.');
};
$maximum_signers = array_replace($post, ['weight_0' => '1', 'high_threshold' => '21']);
for ($i = 1; $i <= 20; $i++) {
    $maximum_signers['account_' . $i] = KeyPair::random()->getAccountId();
    $maximum_signers['weight_' . $i] = '1';
}
$tests['POST rejects threshold requiring twenty-one signatures'] = function () use ($render, $maximum_signers, $Twig, $Signer): void {
    $render($maximum_signers);
    assertMultisigSame(['tools_multisig.errors.impossible_to_collect_signers'], $Twig->context['errors'], 'Threshold must be reachable with at most twenty signatures.');
    assertMultisigSame(null, $Signer->xdr, 'Unreachable threshold must not reach signing.');
};
$tests['POST accepts twenty-signature boundary'] = function () use ($render, $maximum_signers, $Twig, $decode): void {
    $render(array_replace($maximum_signers, ['high_threshold' => '20']));
    assertMultisigSame([], $Twig->context['errors'], 'Exactly twenty signatures must remain supported.');
    assertMultisigSame(20, $decode()[0]['high'], 'XDR must retain the accepted threshold.');
};
$tests['POST uses strongest twenty weights'] = function () use ($render, $maximum_signers, $Twig, $decode): void {
    $render(array_replace($maximum_signers, ['weight_20' => '2']));
    assertMultisigSame([], $Twig->context['errors'], 'The strongest twenty of twenty-one keys may satisfy the threshold.');
    assertMultisigSame(21, $decode()[0]['high'], 'XDR must retain the threshold reached by the strongest keys.');
};

$failures = [];
foreach ($tests as $name => $test) {
    try {
        $test();
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
    }
}
if ($failures) {
    throw new RuntimeException(implode("\n", $failures));
}
fwrite(STDOUT, "Multisig controller tests passed.\n");
