<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\Account;
use Montelibero\BSN\AssetVersions;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\AssetSwapController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\TokenLabelFormatter;
use Montelibero\BSN\TwigExtension;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
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
$BSN = new class extends BSN {
    public function __construct() {}
    public function makeAccountById(string $id): Account { return Account::fromId($id); }
    public function getDataLoadedAt(): ?int { return 1; }
};
$Metadata = new class('HELD-' . $holder) extends TokensController {
    public function __construct(private readonly string $known_key) {}
    public function getKnownToken(string $key): ?array { return $key === $this->known_key ? ['key' => $key] : null; }
};
(new ReflectionProperty(TokensController::class, 'BSN'))->setValue($Metadata, $BSN);
$Contacts = new class extends CurrentContacts {
    public function __construct() {}
    public function getContactName(mixed $account): ?string { return null; }
};
$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');

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
] as $name => $value) {
    $Reflection->getProperty($name)->setValue($Controller, $value);
}
$Account = AccountResponse::fromJson([
    'account_id' => $issuer, 'sequence' => '1',
    'balances' => [
        ['asset_type' => 'native', 'balance' => '100'],
        [
            'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4, 'asset_code' => 'HELD', 'asset_issuer' => $holder,
            'balance' => '500.0000000', 'selling_liabilities' => '100.0000000', 'is_authorized' => true,
        ],
    ],
]);
$held = $Reflection->getMethod('buildTokenRows')->invoke($Controller, $Account);
$issued = $Reflection->getMethod('buildIssuedTokenRows')->invoke($Controller, $issuer);
$tokens = $held + $issued;
$held_key = 'HELD-' . $holder;
$live_key = 'LIVE-' . $issuer;
$zero_key = 'ZERO-' . $issuer;
assertAssetSwap(count($held) === 1, 'Native XLM must stay excluded.');
assertAssetSwap($held[$held_key]['available'] === '400.0000000', 'Selling liabilities must reduce the available amount.');
assertAssetSwap($held[$held_key]['is_known'], 'Balance rows must receive shared token metadata.');
assertAssetSwap(count($issued) === 4, 'Issuer assets must include every Horizon page.');
assertAssetSwap($issued[$live_key]['available_unlimited'] && $issued[$live_key]['available'] === null, 'Issuer availability must be unlimited.');
assertAssetSwap($issued[$zero_key]['without_emission'], 'Zero current supply must be grouped under details.');
assertAssetSwap(!$issued['POOL-' . $issuer]['without_emission'], 'Pool supply must count as issuance.');
assertAssetSwap(!$issued['SAC-' . $issuer]['without_emission'], 'Archived contract supply must count as issuance.');

$errors = [];
$validate = $Reflection->getMethod('validateSendAmount');
$validate->invokeArgs($Controller, [$issued[$live_key], '1000', 'a', &$errors]);
assertAssetSwap($errors === [], 'Issuers must be able to send without a balance.');
$validate->invokeArgs($Controller, [$held[$held_key], '401', 'a', &$errors]);
assertAssetSwap(count($errors) === 1, 'Ordinary holders must not spend selling liabilities.');
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

$Twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/twig'));
$Twig->addExtension(new TwigExtension($Translator, new AssetVersions(dirname(__DIR__))));
$Twig->addExtension(new TranslationExtension($Translator));
$html = $Twig->render('components/asset_swap_tokens.twig', ['tokens' => $tokens, 'side' => 'a', 'selected_asset' => '']);
$Dom = new DOMDocument();
@$Dom->loadHTML($html);
$XPath = new DOMXPath($Dom);
assertAssetSwap($XPath->query('//details//input')->length === 1, 'Only zero-supply assets belong inside details.');
assertAssetSwap($XPath->query('//details[@open]')->length === 0, 'Zero-supply assets must be collapsed initially.');
assertAssetSwap(str_contains($html, 'href="/tokens/HELD"') && str_contains($html, 'is-info'), 'Known assets must use the standard token tag.');
assertAssetSwap(str_contains($html, 'In orders') && !str_contains($html, '>500<'), 'Show available funds and locked amount, not the total balance.');
assertAssetSwap(strpos($html, 'value="' . $held_key . '"') < strpos($html, 'value="' . $live_key . '"'), 'Issued assets must follow held assets.');
$selected_html = $Twig->render('components/asset_swap_tokens.twig', ['tokens' => $tokens, 'side' => 'b', 'selected_asset' => $zero_key]);
assertAssetSwap(str_contains($selected_html, 'open') && str_contains($selected_html, 'checked'), 'A selected zero-supply asset must remain visible after reload.');

fwrite(STDOUT, "Asset swap token tests passed.\n");
