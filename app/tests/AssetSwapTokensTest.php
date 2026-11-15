<?php

declare(strict_types=1);

use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\Account;
use Montelibero\BSN\AssetVersions;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Contract;
use Montelibero\BSN\Controllers\AssetSwapController;
use Montelibero\BSN\Controllers\SignController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\Signature;
use Montelibero\BSN\StellarTomlImageManager;
use Montelibero\BSN\TokenLabelFormatter;
use Montelibero\BSN\TwigExtension;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\PaymentOperation;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertAssetSwap(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$issuer = KeyPair::random()->getAccountId();
$holder = KeyPair::random()->getAccountId();
$Holder = Account::fromId($holder);
$Offer = new Contract(hash('sha256', 'HELD token offer'));
$Offer->setText('HELD token offer');
$Holder->addSignature(new Signature($Holder, $Offer, 'HELDTokenOffer'));
$BSN = new class($Holder) extends BSN {
    public function __construct(private readonly Account $Holder) {}
    public function makeAccountById(string $id): Account { return $id === $this->Holder->getId() ? $this->Holder : Account::fromId($id); }
    public function getDataLoadedAt(): ?int { return 1; }
};
$Images = new class extends StellarTomlImageManager {
    public array $requested_keys = [];
    public function __construct() {}
    public function fetchTokenImageMap(array $tokens): array
    {
        $this->requested_keys = array_keys($tokens);
        $images = [];
        foreach ($tokens as $token) {
            $images[$token['key']] = ['public_path' => '/stellar-toml-images/tokens/' . $token['code'] . '.png'];
        }
        return $images;
    }
};
$Metadata = new class('HELD-' . $holder) extends TokensController {
    public function __construct(private readonly string $known_key) {}
    public function getKnownToken(string $key): ?array { return $key === $this->known_key ? ['key' => $key] : null; }
};
(new ReflectionProperty(TokensController::class, 'BSN'))->setValue($Metadata, $BSN);
$Contacts = new class extends CurrentContacts {
    public function __construct() {}
    public function getContactName(mixed $account): ?string { return null; }
    public function getDisplayName(Account|array|string|null $account, bool $ignore_contact = false): string { return 'Test account'; }
};
$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');
$Signer = new class extends SignController {
    public ?string $xdr = null;
    public function __construct() {}
    public function SignTransaction(
        ?string $xdr = null, ?string $uri = null, ?string $description = null, ?string $signing_title = null,
    ): ?string {
        $this->xdr = $xdr;
        return '<form data-signing-result></form>';
    }
};
$Container = new Container();
$Container->set(SignController::class, $Signer);

$asset = static fn (string $code, string $amount): array => [
    'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4,
    'asset_code' => $code,
    'asset_issuer' => $issuer,
    'balances' => ['authorized' => $amount, 'unauthorized' => '0', 'authorized_to_maintain_liabilities' => '0'],
    'claimable_balances_amount' => '0',
    'liquidity_pools_amount' => '0',
];
$pool_asset = $asset('POOL', '0');
$pool_asset['liquidity_pools_amount'] = '1';
$contract_asset = $asset('SAC', '0');
$contract_asset['archived_contracts_amount'] = '0.0000001';
$page = static fn (array $records, array $links = []): Response => new Response(200, [], json_encode([
    '_links' => $links, '_embedded' => ['records' => $records],
], JSON_THROW_ON_ERROR));
$SDK = new StellarSDK('https://horizon.test');
$SDK->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([
    $page([$asset('ZERO', '0'), $asset('LIVE', '12')], ['next' => ['href' => 'https://horizon.test/assets?cursor=next']]),
    $page([$pool_asset, $contract_asset], ['next' => ['href' => 'https://horizon.test/assets?cursor=end']]),
    $page([]),
]))]));

$Reflection = new ReflectionClass(AssetSwapController::class);
$Controller = $Reflection->newInstanceWithoutConstructor();
foreach ([
    'Stellar' => $SDK, 'TokensController' => $Metadata, 'Translator' => $Translator,
    'TokenLabelFormatter' => new TokenLabelFormatter($Metadata, $Contacts),
    'BSN' => $BSN, 'CurrentContacts' => $Contacts, 'TomlImageManager' => $Images,
    'Container' => $Container,
] as $name => $value) {
    $Reflection->getProperty($name)->setValue($Controller, $value);
}
$account_data = [
    'account_id' => $issuer, 'sequence' => '1',
    'balances' => [
        ['asset_type' => 'native', 'balance' => '100'],
        [
            'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4, 'asset_code' => 'HELD', 'asset_issuer' => $holder,
            'balance' => '500.0000000', 'selling_liabilities' => '100.0000000', 'is_authorized' => true,
        ],
        [
            'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4, 'asset_code' => 'SOZ', 'asset_issuer' => $holder,
            'balance' => '0.6319482', 'selling_liabilities' => '0.6319482', 'is_authorized' => true,
        ],
        [
            'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4, 'asset_code' => 'EMPTY', 'asset_issuer' => $holder,
            'balance' => '0.0000000', 'is_authorized' => true,
        ],
        [
            'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4, 'asset_code' => 'DENY', 'asset_issuer' => $holder,
            'balance' => '1.0000000', 'is_authorized' => false,
        ],
    ],
];
$Account = AccountResponse::fromJson($account_data);
$held = $Reflection->getMethod('buildTokenRows')->invoke($Controller, $Account);
$issued = $Reflection->getMethod('buildIssuedTokenRows')->invoke($Controller, $issuer);
$tokens = $held + $issued;
$held_key = 'HELD-' . $holder;
$live_key = 'LIVE-' . $issuer;
$zero_key = 'ZERO-' . $issuer;
$locked_key = 'SOZ-' . $holder;
assertAssetSwap(count($held) === 2, 'Native XLM, empty balances and unauthorized assets must stay excluded.');
assertAssetSwap($held[$locked_key]['available'] === '0.0000000', 'Fully committed balances must remain visible with zero availability.');
assertAssetSwap($held[$held_key]['available'] === '400.0000000', 'Selling liabilities must reduce the available amount.');
assertAssetSwap($held[$held_key]['is_known'], 'Balance rows must receive shared token metadata.');
assertAssetSwap(count($issued) === 4, 'Issuer assets must include every Horizon page.');
assertAssetSwap($issued[$live_key]['available_unlimited'] && $issued[$live_key]['available'] === null, 'Issuer availability must be unlimited.');
assertAssetSwap($issued[$zero_key]['without_emission'], 'Zero current supply must be grouped under details.');
assertAssetSwap(!$issued['POOL-' . $issuer]['without_emission'], 'Pool supply must count as issuance.');
assertAssetSwap(!$issued['SAC-' . $issuer]['without_emission'], 'Archived contract supply must count as issuance.');

$SDK->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(200, [], json_encode($account_data, JSON_THROW_ON_ERROR)),
    $page([$asset('ZERO', '0'), $asset('LIVE', '12'), $pool_asset, $contract_asset], ['next' => ['href' => 'https://horizon.test/assets?cursor=end']]),
    $page([]),
]))]));
$errors = [];
$state = $Reflection->getMethod('buildSideState')->invokeArgs($Controller, ['a', $issuer, true, &$errors]);
assertAssetSwap($errors === [] && $state['loaded'], 'Side state must load balances and issued assets.');
$tokens = $state['tokens'];
assertAssetSwap($Images->requested_keys === array_keys($tokens), 'Images must be loaded together for held and issued assets.');
assertAssetSwap($tokens[$held_key]['image_path'] === '/stellar-toml-images/tokens/HELD.png', 'Held assets must receive cached images.');
assertAssetSwap($tokens[$zero_key]['image_path'] === '/stellar-toml-images/tokens/ZERO.png', 'Issued assets must receive cached images.');
assertAssetSwap($tokens[$held_key]['has_offer_text'], 'Assets with a verified offer must be marked.');

$errors = [];
$warnings = [];
$validate = $Reflection->getMethod('validateSendAmount');
$validate->invokeArgs($Controller, [$issued[$live_key], '1000', 'a', &$errors, &$warnings]);
assertAssetSwap($errors === [] && $warnings === [], 'Issuers must be able to send without a balance or a locked-funds warning.');
$validate->invokeArgs($Controller, [$held[$held_key], '400', 'a', &$errors, &$warnings]);
assertAssetSwap($errors === [] && $warnings === [], 'Amounts within available funds must not produce warnings.');
$validate->invokeArgs($Controller, [$held[$held_key], '401', 'a', &$errors, &$warnings]);
assertAssetSwap($errors === [] && count($warnings) === 1, 'Amounts within the balance but above availability must warn without blocking preparation.');
assertAssetSwap(str_contains($warnings['a'], 'HELD') && !str_contains($warnings['a'], '%'), 'Locked-funds warnings must identify the asset and resolve all parameters.');
$errors = [];
$warnings = [];
$validate->invokeArgs($Controller, [$held[$locked_key], '0.0000001', 'a', &$errors, &$warnings]);
assertAssetSwap($errors === [] && count($warnings) === 1, 'Fully committed balances must allow preparation with a warning.');
assertAssetSwap(str_contains($warnings['a'], 'release 0.0000001 SOZ'), 'The warning must preserve the exact amount to release without float rounding.');
$errors = [];
$warnings = [];
$validate->invokeArgs($Controller, [$held[$locked_key], '0.6319483', 'a', &$errors, &$warnings]);
assertAssetSwap(count($errors) === 1 && $warnings === [], 'Amounts above the total balance must remain blocked.');
$errors = [];
$receive = $Reflection->getMethod('analyzeReceiver')->invokeArgs($Controller, [
    AccountResponse::fromJson(['account_id' => $holder, 'sequence' => '1', 'balances' => []]),
    $holder, $held[$locked_key], '0.0000001', 'b', &$errors,
]);
assertAssetSwap($errors === [] && !$receive['needs_trustline'], 'Returning a token to its issuer must not require a receiver trustline.');
$errors = [];
$amount = $Reflection->getMethod('normalizeAmount')->invokeArgs($Controller, ['922337203685.4775808', 'a', &$errors]);
assertAssetSwap($amount === null && count($errors) === 1, 'Unlimited issuance must still obey the XDR amount limit.');
$operations = $Reflection->getMethod('buildOperations')->invoke(
    $Controller, $issuer, $holder, $issued[$zero_key], '1', $held[$held_key], '2',
    ['needs_trustline' => false], ['needs_trustline' => true],
);
assertAssetSwap(count($operations) === 3, 'First issuance must include the receiver trustline and both payments.');
assertAssetSwap($operations[1]->getSourceAccount() === null, 'Side A payment must inherit the transaction source.');
assertAssetSwap($operations[2]->getSourceAccount()->getAccountId() === $holder, 'Side B payment must explicitly use side B.');
$Transaction = new TransactionBuilder($Account);
$Transaction->addOperations($operations);
assertAssetSwap($Transaction->build()->toEnvelopeXdrBase64() !== '', 'An issuer exchange must serialize to XDR.');

$SideBAccount = AccountResponse::fromJson([
    'account_id' => $holder, 'sequence' => '2',
    'balances' => [[
        'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4, 'asset_code' => 'LIVE', 'asset_issuer' => $issuer,
        'balance' => '12.0000000', 'selling_liabilities' => '0.0000000', 'is_authorized' => true,
    ]],
]);
$side_b_state = [
    'account_id' => $holder,
    'account_response' => $SideBAccount,
    'tokens' => $Reflection->getMethod('buildTokenRows')->invoke($Controller, $SideBAccount),
];
$selected = ['a_asset' => $locked_key, 'a_amount' => '0.6319482', 'b_asset' => $live_key, 'b_amount' => '2', 'memo' => 'Private swap'];
$errors = [];
$warnings = [];
$build = $Reflection->getMethod('buildSigningForm');
$signing_form = $build->invokeArgs($Controller, [$state, $side_b_state, $selected, &$errors, &$warnings]);
assertAssetSwap($errors === [] && count($warnings) === 1 && $signing_form !== null && $Signer->xdr !== null, 'An exchange of a fully committed balance must reach signing with a warning.');
$signing_warning = $warnings['a'];
$Prepared = AbstractTransaction::fromEnvelopeBase64XdrString($Signer->xdr);
assertAssetSwap($Prepared instanceof Transaction && $Prepared->getSignatures() === [], 'Prepared exchanges must remain unsigned.');
$prepared_operations = $Prepared->getOperations();
assertAssetSwap(count($prepared_operations) === 2 && $prepared_operations[0] instanceof PaymentOperation && $prepared_operations[1] instanceof PaymentOperation, 'Preparation must contain only both payments, without automatic order cancellation or issuer trustlines.');
assertAssetSwap($prepared_operations[0]->getAmount() === '0.6319482' && $prepared_operations[0]->getDestination()->getAccountId() === $holder, 'The locked token must return to its issuer in the requested amount.');
$Signer->xdr = null;
$selected['a_amount'] = '0.6319483';
$errors = [];
$warnings = [];
$signing_form = $build->invokeArgs($Controller, [$state, $side_b_state, $selected, &$errors, &$warnings]);
assertAssetSwap(count($errors) === 1 && $signing_form === null && $Signer->xdr === null, 'An amount above the total balance must never reach signing.');

$Twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/twig'));
$Twig->addExtension(new TwigExtension($Translator, new AssetVersions(dirname(__DIR__))));
$Twig->addExtension(new TranslationExtension($Translator));
$page_html = $Twig->render('tools_asset_swap.twig', [
    'side_a' => $issuer, 'side_b' => $holder,
    'side_a_state' => $state, 'side_b_state' => $side_b_state,
    'selected' => $selected, 'errors' => [], 'warnings' => ['a' => $signing_warning],
    'signing_form' => '<div id="prepared-swap"></div>',
]);
assertAssetSwap(str_contains($page_html, 'notification is-warning') && str_contains($page_html, $signing_warning), 'The prepared exchange must display its locked-funds warning.');
assertAssetSwap(strpos($page_html, $signing_warning) < strpos($page_html, 'id="prepared-swap"'), 'The warning must be visible before the signing controls.');
$html = $Twig->render('components/asset_swap_tokens.twig', ['tokens' => $tokens, 'side' => 'a', 'selected_asset' => '', 'account_id' => $issuer]);
$Dom = new DOMDocument();
@$Dom->loadHTML($html);
$XPath = new DOMXPath($Dom);
assertAssetSwap($XPath->query('//details//input')->length === 1, 'Only zero-supply assets belong inside details.');
assertAssetSwap($XPath->query('//details[@open]')->length === 0, 'Zero-supply assets must be collapsed initially.');
assertAssetSwap(str_contains($html, 'href="/tokens/HELD"') && str_contains($html, 'is-info'), 'Known assets must use the standard token tag.');
assertAssetSwap($XPath->query('//span[contains(@class, "token-tag-logo") and contains(@style, "/stellar-toml-images/tokens/HELD.png")]')->length === 1, 'Token images must be rendered.');
assertAssetSwap($XPath->query('//span[contains(@class, "is-success") and normalize-space(.)="Offer"]')->length === 1, 'Only verified offers must have a visible offer badge.');
assertAssetSwap($XPath->query('//input[@value="' . $locked_key . '"]')->length === 1 && $XPath->query('//input[@disabled]')->length === 0, 'All held and issued assets, including fully committed balances, must remain selectable.');
assertAssetSwap(!str_contains($html, 'Funds in orders are locked.') && !str_contains($html, '/tools/orders'), 'Token rows must not repeat the warning or orders link.');
assertAssetSwap(str_contains($page_html, 'href="/tools/orders?current_account=' . $issuer . '"'), 'The warning orders link must use the sending side account.');
assertAssetSwap(str_contains($html, ', in orders') && !str_contains($html, '>500<'), 'Show available funds and locked amount, not the total balance.');
assertAssetSwap(strpos($html, 'value="' . $held_key . '"') < strpos($html, 'value="' . $live_key . '"'), 'Issued assets must follow held assets.');
$selected_html = $Twig->render('components/asset_swap_tokens.twig', ['tokens' => $tokens, 'side' => 'b', 'selected_asset' => $zero_key, 'account_id' => $issuer]);
assertAssetSwap(str_contains($selected_html, 'open') && str_contains($selected_html, 'checked'), 'A selected zero-supply asset must remain visible after reload.');
$selected_html = $Twig->render('components/asset_swap_tokens.twig', ['tokens' => $tokens, 'side' => 'b', 'selected_asset' => $locked_key, 'account_id' => $issuer]);
@$Dom->loadHTML($selected_html);
$XPath = new DOMXPath($Dom);
assertAssetSwap($XPath->query('//input[@checked and not(@disabled) and @value="' . $locked_key . '"]')->length === 1, 'A selected locked token must remain selected and enabled after reload.');

fwrite(STDOUT, "Asset swap token tests passed.\n");
