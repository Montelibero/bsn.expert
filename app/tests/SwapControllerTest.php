<?php

declare(strict_types=1);

use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\SignController;
use Montelibero\BSN\Controllers\SwapController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Montelibero\BSN\StellarTomlImageManager;
use Montelibero\BSN\TokenLabelFormatter;
use Psr\Http\Message\RequestInterface;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\PathPaymentStrictReceiveOperation;
use Soneso\StellarSDK\PathPaymentStrictSendOperation;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertSwapSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$source = KeyPair::random()->getAccountId();
$recipient = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$BSN = new class extends BSN {
    public function __construct() {}
    public function makeAccountById(string $id): Account { return Account::fromId($id); }
};
$Contacts = new class extends CurrentContacts {
    public function __construct() {}
    public function serialize(Account $Account, bool $ignore_contact = false): array
    {
        return ['id' => $Account->getId(), 'display_name' => $Account->getShortId()];
    }
    public function getContactName(Account|array|string|null $account): ?string { return null; }
};
$Tokens = new class extends TokensController {
    public function __construct() {}
    public function getKnownTokenByCode(string $code): ?array { return null; }
    public function getKnownToken(string $key): ?array { return null; }
};
$Images = new class extends StellarTomlImageManager {
    public function __construct() {}
    public function applyTokenImage(array &$token): void {}
};
$Signer = new class extends SignController {
    public ?string $xdr = null;
    public function __construct() {}
    public function SignTransaction(
        ?string $xdr = null, ?string $uri = null, ?string $description = null, ?string $signing_title = null,
    ): ?string {
        $this->xdr = $xdr;
        return '<form data-signing-result><button>Sign</button></form>';
    }
};
$Container = new Container();
$Container->set(SignController::class, $Signer);
$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
foreach (['en', 'ru'] as $locale) {
    $Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.' . $locale . '.yaml', $locale);
}
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
$Stellar = new StellarSDK('https://horizon.invalid');
$path_requests = [];
$Stellar->setHttpClient(new Client([
    'base_uri' => 'https://horizon.invalid',
    'handler' => function (RequestInterface $request) use ($source, $recipient, $issuer, &$path_requests) {
        $path = $request->getUri()->getPath();
        $data = ['_links' => [], '_embedded' => ['records' => []]];
        if (str_starts_with($path, '/accounts/')) {
            $id = substr($path, strlen('/accounts/'));
            assertSwapSame(true, in_array($id, [$source, $recipient], true), 'Only fixture accounts may be requested.');
            $data = ['account_id' => $id, 'sequence' => '123', 'balances' => []];
            foreach ($id === $source ? ['EURMTL', 'SOZ'] : ['SOZ'] as $code) {
                $data['balances'][] = [
                    'asset_type' => strlen($code) > 4 ? 'credit_alphanum12' : 'credit_alphanum4',
                    'asset_code' => $code, 'asset_issuer' => $issuer, 'balance' => '1000.0000000',
                    'limit' => '10000.0000000', 'is_authorized' => true,
                    'buying_liabilities' => '0', 'selling_liabilities' => '0',
                ];
            }
        } elseif (str_starts_with($path, '/paths/')) {
            parse_str($request->getUri()->getQuery(), $query);
            $path_requests[] = [$path, $query];
            $send = $path === '/paths/strict-send';
            $data['_embedded']['records'][] = [
                'source_asset_type' => 'credit_alphanum12', 'source_asset_code' => 'EURMTL',
                'source_asset_issuer' => $issuer,
                'source_amount' => $send ? $query['source_amount'] : bcmul($query['destination_amount'], '30', 7),
                'destination_asset_type' => 'credit_alphanum4', 'destination_asset_code' => 'SOZ',
                'destination_asset_issuer' => $issuer,
                'destination_amount' => $send ? bcmul($query['source_amount'], '0.033', 7) : $query['destination_amount'],
                'path' => [],
            ];
        } else {
            assertSwapSame('/assets', $path, 'Unexpected Horizon request.');
        }
        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR)));
    },
]));
$CurrentUser = new CurrentUser($BSN, new RequestSession(false));
$Controller = new SwapController(
    $BSN, $CurrentUser, $Contacts, $Twig, $Stellar, $Translator, $Tokens,
    new TokenLabelFormatter($Tokens, $Contacts), $Images, new StellarAccountReserveCalculator($Stellar), $Container,
);
$render = function (array $query = []) use ($source, $Controller, $CurrentUser, $Signer, &$path_requests): DOMXPath {
    $_GET = $query + ['current_account' => $source];
    $_POST = $_COOKIE = $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/tools/swap?' . http_build_query($_GET);
    $CurrentUser->beginRequest();
    $Signer->xdr = null;
    $path_requests = [];
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?>' . $Controller->Swap());
    return new DOMXPath($document);
};
$decode = function () use ($Signer, $source): PathPaymentStrictSendOperation|PathPaymentStrictReceiveOperation {
    $transaction = AbstractTransaction::fromEnvelopeBase64XdrString($Signer->xdr ?? '');
    assertSwapSame(true, $transaction instanceof Transaction, 'A regular transaction must be prepared.');
    assertSwapSame($source, $transaction->getSourceAccount()->getAccountId(), 'XDR source must stay selected.');
    assertSwapSame('124', $transaction->getSequenceNumber()->toString(), 'Sequence must come from the source account.');
    assertSwapSame([], $transaction->getSignatures(), 'Prepared exchange must remain unsigned.');
    assertSwapSame(1, count($transaction->getOperations()), 'Exchange must contain one operation.');
    return $transaction->getOperations()[0];
};
$pair = ['send_asset' => 'EURMTL-' . $issuer, 'receive_asset' => 'SOZ-' . $issuer];

$html = $render();
assertSwapSame([], $Twig->context['errors'], 'Initial form must be quiet.');
assertSwapSame(1, $html->query('//form[@method="get" and @action="/tools/swap"]')->length, 'Form must submit via GET.');
assertSwapSame(0, $html->query('//details[@open]')->length, 'Same-account recipient must be collapsed.');
assertSwapSame(1, $html->query('//summary/span[contains(@class, "pseudo-link")]')->length, 'Change recipient must use the shared pseudo-link style.');
assertSwapSame($source, $html->evaluate('string(//input[@name="current_account"]/@value)'), 'GET must preserve selected account.');

foreach (['en', 'ru'] as $locale) {
    $Translator->setLocale($locale);
    $html = $render($pair + ['fix' => 'send', 'send_amount' => '100', 'receive_amount' => '999']);
    assertSwapSame(false, str_contains((string) $html->document->saveHTML(), 'tools_swap.'), 'Swap form labels must be translated in both languages.');
    assertSwapSame([], $Twig->context['errors'], 'Exact-send calculation must succeed.');
    assertSwapSame('100', $html->evaluate('string(//input[@name="send_amount"]/@value)'), 'Entered amount must stay exact.');
    assertSwapSame('3.3', $html->evaluate('string(//input[@name="receive_amount"]/@value)'), 'Other side must come from a fresh quote.');
    assertSwapSame('send', $html->evaluate('string(//input[@name="fix" and @checked]/@value)'), 'Exact side must survive server rendering.');
    assertSwapSame(1, $html->query('//*[@data-signing-result]/parent::div/following-sibling::form[@data-swap-form]')->length, 'Signing result must precede the editable form.');
    foreach (['send' => 'exact', 'receive' => 'estimated'] as $side => $label) {
        assertSwapSame($Translator->trans('tools_swap.form.' . $label), $html->evaluate('normalize-space(//p[@id="swap_' . $side . '_status"]/span[not(@hidden)])'), 'Amount status must remain a simple label.');
        assertSwapSame(0, $html->query('//p[@id="swap_' . $side . '_status"]//a')->length, 'Amount status must not repeat the token or limit.');
    }
    $operation = $decode();
    assertSwapSame(true, $operation instanceof PathPaymentStrictSendOperation, 'Send radio must produce strict-send.');
    assertSwapSame('100.0000000', $operation->getSendAmount(), 'Strict-send amount must match input.');
    assertSwapSame('3.1350000', $operation->getDestMin(), 'Minimum must include the 5 percent tolerance.');
    assertSwapSame($source, $operation->getDestination()->getAccountId(), 'Default destination must be the source.');

    $html = $render($pair + ['fix' => 'receive', 'send_amount' => '999', 'receive_amount' => '3', 'destination' => $recipient]);
    assertSwapSame([], $Twig->context['errors'], 'Exact-receive calculation must succeed.');
    assertSwapSame('90', $html->evaluate('string(//input[@name="send_amount"]/@value)'), 'Send amount must be calculated.');
    assertSwapSame('3', $html->evaluate('string(//input[@name="receive_amount"]/@value)'), 'Receive amount must stay exact.');
    assertSwapSame(1, $html->query('//details[@open]')->length, 'Another recipient must be disclosed.');
    assertSwapSame(1, $html->query('//span[@data-swap-other-recipient]//a[@href="/accounts/' . $recipient . '"]')->length, 'Recipient identity must remain in the summary.');
    $operation = $decode();
    assertSwapSame(true, $operation instanceof PathPaymentStrictReceiveOperation, 'Receive radio must produce strict-receive.');
    assertSwapSame('3.0000000', $operation->getDestAmount(), 'Received amount must be exact.');
    assertSwapSame('94.5000000', $operation->getSendMax(), 'Maximum must include the 5 percent tolerance.');
    assertSwapSame($recipient, $operation->getDestination()->getAccountId(), 'XDR must target the chosen recipient.');
}

foreach (['send' => '100', 'receive' => '3'] as $fix => $amount) {
    $render(['send' => $pair['send_asset'], 'receive' => $pair['receive_asset'], 'fix' => $fix, 'amount' => $amount]);
    assertSwapSame([], $Twig->context['errors'], 'Legacy GET aliases must remain supported.');
    assertSwapSame($amount, $Twig->context['values'][$fix . '_amount'], 'Legacy amount must initialize the exact side.');
    assertSwapSame('/paths/strict-' . $fix, $path_requests[0][0], 'GET mode must select the right path API.');
}
$render($pair + ['receive_amount' => '3']);
assertSwapSame('receive', $Twig->context['values']['fix'], 'A receive-only GET prefill must select that side.');

foreach (['send' => '100', 'receive' => '3'] as $fix => $amount) {
    $html = $render($pair + ['fix' => $fix, $fix . '_amount' => $amount, 'destination' => $recipient, 'action' => 'recipient']);
    assertSwapSame([], $Twig->context['errors'], 'Applying a recipient must quietly refresh token options.');
    assertSwapSame($recipient, $Twig->context['destination_account_id'], 'Recipient refresh must use the new account.');
    assertSwapSame([$pair['receive_asset']], array_keys($Twig->context['destination_tokens']), 'Only recipient tokens must be offered.');
    assertSwapSame($pair['receive_asset'], $Twig->context['values']['receive_asset'], 'A compatible selected token must be retained.');
    assertSwapSame($amount, $Twig->context['values'][$fix . '_amount'], 'Recipient refresh must preserve the exact amount.');
    assertSwapSame($fix, $Twig->context['values']['fix'], 'Recipient refresh must preserve the exact side.');
    assertSwapSame(null, $Signer->xdr, 'Applying a recipient must not build a transaction.');
    assertSwapSame([], $path_requests, 'Applying a recipient must not request swap paths.');
    assertSwapSame(1, $html->query('//input[@name="destination"]/parent::div/following-sibling::div/button[@name="action" and @value="recipient"]')->length, 'Apply button must be attached to the recipient input.');
}
$render(['send_asset' => $pair['send_asset'], 'receive_asset' => $pair['send_asset'], 'send_amount' => '100', 'destination' => $recipient, 'action' => 'recipient']);
assertSwapSame('', $Twig->context['values']['receive_asset'], 'An unavailable token must be cleared instead of silently replaced.');
assertSwapSame([], $Twig->context['errors'], 'Clearing an unavailable token must not report a quote error.');

foreach ([
    $pair,
    $pair + ['fix' => 'receive', 'send_amount' => '100', 'receive_amount' => ''],
    $pair + ['send_amount' => '', 'amount' => '100'],
    ['send_amount' => '100'],
    ['send_asset' => $pair['send_asset'], 'receive_asset' => '', 'send_amount' => '100'],
    ['send_asset' => $pair['send_asset'], 'receive_asset' => 'unavailable', 'send_amount' => '100', 'destination' => $recipient],
] as $partial) {
    $render($partial);
    assertSwapSame([], $Twig->context['errors'], 'Incomplete input must quietly refresh the form.');
    assertSwapSame(null, $Signer->xdr, 'Incomplete input must not prepare a transaction.');
    assertSwapSame([], $path_requests, 'Incomplete input must not request a quote.');
}
$render($pair + ['send_amount' => '100', 'destination' => $recipient, 'action' => 'self']);
assertSwapSame('', $Twig->context['values']['destination'], 'Self button must clear a previous recipient without JS.');
assertSwapSame($source, $decode()->getDestination()->getAccountId(), 'Self button must reset the XDR destination.');

$render($pair + ['send_amount' => 'invalid']);
assertSwapSame(null, $Signer->xdr, 'Invalid exact amount must not build XDR.');
assertSwapSame(true, $Twig->context['errors'] !== [], 'Invalid complete input must report validation errors.');

$Reflection = new ReflectionClass(SwapController::class);
foreach ([
    ['addPercentCeil', '90', '5', '94.5000000'],
    ['subtractPercentFloor', '3.3', '5', '3.1350000'],
    ['addPercentCeil', '0.0000001', '0.0000001', '0.0000002'],
    ['subtractPercentFloor', '0.0000001', '0.0000001', '0.0000000'],
    ['addPercentCeil', '999999999.9999999', '0', '999999999.9999999'],
    ['subtractPercentFloor', '999999999.9999999', '0', '999999999.9999999'],
] as [$method, $amount, $percent, $expected]) {
    assertSwapSame($expected, $Reflection->getMethod($method)->invoke($Controller, $amount, $percent), 'Bounds must round exactly to Stellar precision.');
}
fwrite(STDOUT, "Swap controller tests passed.\n");
