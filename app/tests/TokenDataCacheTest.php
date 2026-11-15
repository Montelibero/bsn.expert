<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\Account;
use Montelibero\BSN\AssetVersions;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\MongoCacheManager;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarTomlImageManager;
use Montelibero\BSN\TwigExtension;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED);

function assertTokenCache(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$issuer = KeyPair::random()->getAccountId();
$other_issuer = KeyPair::random()->getAccountId();
$holder = KeyPair::random()->getAccountId();
$Cache = new class extends MongoCacheManager {
    public array $entries = [];
    public function __construct() {}
    public function fetch(string $key): ?array { return $this->entries[$key] ?? null; }
    public function store(string $key, mixed $data, ?int $ttl = null, array $meta = []): array
    {
        return $this->entries[$key] = ['data' => $data];
    }
};
$Controller = new class extends TokensController {
    public function __construct() {}
    public function getKnownTokenByCode(string $code): ?array { return null; }
};
$SDK = new StellarSDK('https://horizon.test');
$Mock = new MockHandler();
$SDK->setHttpClient(new Client(['handler' => HandlerStack::create($Mock)]));
$Reflection = new ReflectionClass(TokensController::class);
$Reflection->getProperty('Stellar')->setValue($Controller, $SDK);
$Reflection->getProperty('CacheManager')->setValue($Controller, $Cache);
$fetch = $Reflection->getMethod('fetchTokenSnapshot');
$cache_key = 'token_snapshot:' . $issuer . ':TEST';
$page = static fn (array $records): Response => new Response(200, [], json_encode([
    '_embedded' => ['records' => $records],
], JSON_THROW_ON_ERROR));
$asset = static fn (string $supply): array => [
    'asset_type' => 'credit_alphanum4', 'asset_code' => 'TEST', 'asset_issuer' => $issuer,
    'accounts' => ['authorized' => 1, 'unauthorized' => 0, 'authorized_to_maintain_liabilities' => 0],
    'balances' => ['authorized' => $supply, 'unauthorized' => '0', 'authorized_to_maintain_liabilities' => '0'],
];
$account = static fn (string $balance): array => [
    'account_id' => $holder, 'sequence' => '1',
    'balances' => [[
        'asset_type' => 'credit_alphanum4', 'asset_code' => 'TEST', 'asset_issuer' => $issuer,
        'balance' => $balance,
    ]],
];

$Mock->append($page([$asset('10')]), $page([$account('10')]));
$first = $fetch->invoke($Controller, 'TEST', $issuer, true, false);
assertTokenCache(!$first['warning'] && !$first['from_cache'], 'First load must fetch a complete snapshot.');
assertTokenCache($first['asset']['issued'] === 10.0 && $first['holders'][0]['amount'] === '10', 'Supply and holders must come from the same refresh.');
$second = $fetch->invoke($Controller, 'TEST', $issuer, true, false);
assertTokenCache($second['from_cache'] && !$second['warning'], 'A fresh cache hit must make no HTTP requests.');

$Mock->append($page([$asset('20')]), $page([$account('20')]));
$updated = $fetch->invoke($Controller, 'TEST', $issuer, true, true);
assertTokenCache($updated['asset']['issued'] === 20.0 && $updated['holders'][0]['amount'] === '20', 'Forced refresh must replace both supply and holders.');

$saved = $Cache->entries[$cache_key];
$Mock->append($page([$asset('30')]), new Response(503));
$failed = $fetch->invoke($Controller, 'TEST', $issuer, true, true);
assertTokenCache($failed['warning'] && $failed['from_cache'], 'Holder fetch failure must return the previous cache.');
assertTokenCache($failed['asset']['issued'] === 20.0 && $Cache->entries[$cache_key] === $saved, 'Partial refresh must not overwrite the previous snapshot or its timestamp.');

$Cache->entries[$cache_key]['data']['fetched_at'] = time() - 3601;
$Mock->append(new Response(503));
$stale = $fetch->invoke($Controller, 'TEST', $issuer, true, false);
assertTokenCache($stale['warning'] && $stale['is_stale_cache'] && $stale['asset']['issued'] === 20.0, 'Expired data must remain available when automatic refresh fails.');
$Mock->append($page([$asset('40')]), $page([$account('40')]));
$recovered = $fetch->invoke($Controller, 'TEST', $issuer, true, false);
assertTokenCache(!$recovered['warning'] && !$recovered['from_cache'] && $recovered['asset']['issued'] === 40.0, 'Expired snapshots must automatically recover.');

$Mock->append($page([]));
$empty = $fetch->invoke($Controller, 'NONE', $issuer, true, false);
$empty_cached = $fetch->invoke($Controller, 'NONE', $issuer, true, false);
assertTokenCache($empty['asset'] === null && !$empty['warning'] && $empty_cached['from_cache'] && !$empty_cached['warning'], 'A missing asset is a cacheable response, not an upstream error.');
$Cache->entries['token_snapshot:' . $issuer . ':NONE']['data']['fetched_at'] = time() - 301;
$Mock->append($page([]));
$empty_expired = $fetch->invoke($Controller, 'NONE', $issuer, true, false);
assertTokenCache(!$empty_expired['from_cache'] && !$empty_expired['warning'], 'Missing assets must be retried after five minutes.');

$Mock->append(new Response(503));
$unavailable = $fetch->invoke($Controller, 'TEST', $other_issuer, true, false);
assertTokenCache($unavailable['warning'] && $unavailable['fetched_at'] === null && $unavailable['asset'] === null, 'Same code with another issuer must not reuse a cached token.');
assertTokenCache(!isset($Cache->entries['token_snapshot:' . $other_issuer . ':TEST']), 'Failed first load must not cache an empty successful result.');

$BSN = new class extends BSN {
    private array $test_accounts = [];
    public function __construct() {}
    public function makeAccountById(string $id): Account { return $this->test_accounts[$id] ??= Account::fromId($id); }
    public function getDataLoadedAt(): ?int { return 1; }
};
$BSN->makeAccountById($issuer)->addBalanceRecord('MTLAP', 4);
$Session = new RequestSession(false);
$User = new CurrentUser($BSN, $Session);
$Images = new class extends StellarTomlImageManager {
    public function __construct() {}
    public function fetchTokenImages(string $code, string $issuer): array { return []; }
};
$PageTwig = new Environment(new ArrayLoader([
    'token.twig' => '{{ token_refresh|json_encode|raw }}',
]));
foreach (['BSN' => $BSN, 'RequestSession' => $Session, 'CurrentUser' => $User, 'TomlImageManager' => $Images, 'Twig' => $PageTwig] as $name => $value) {
    $Reflection->getProperty($name)->setValue($Controller, $value);
}
$_SESSION = [];
$_GET = ['refresh_data_code' => 'invalid', 'current_account' => $issuer];
$_SERVER['REQUEST_URI'] = '/tokens/TEST-' . $issuer . '?current_account=' . $issuer . '&open_trustline=no';
$anonymous = json_decode($Controller->Token('TEST-' . $issuer), true, 512, JSON_THROW_ON_ERROR);
assertTokenCache(!$anonymous['can_refresh'] && $anonymous['code'] === null && $_SESSION === [], 'An anonymous selected account must neither authorize refresh nor create session state.');

$_SESSION = ['account' => ['id' => $holder]];
foreach ([0, 3.9999999] as $balance) {
    $BSN->makeAccountById($holder)->addBalanceRecord('MTLAP', $balance);
    $below_threshold = json_decode($Controller->Token('TEST-' . $issuer), true, 512, JSON_THROW_ON_ERROR);
    assertTokenCache(!$below_threshold['can_refresh'] && $below_threshold['code'] === null, 'A signer below 4 MTLAP must not gain refresh access from the selected account.');
    assertTokenCache(!isset($_SESSION['_request_tokens']), 'Ineligible signers must not receive refresh tokens.');
}
$BSN->makeAccountById($holder)->addBalanceRecord('MTLAP', 4);
$authorized = json_decode($Controller->Token('TEST-' . $issuer), true, 512, JSON_THROW_ON_ERROR);
assertTokenCache($authorized['can_refresh'] && strlen($authorized['code']) === 64, 'A verified signer with exactly 4 MTLAP must receive a refresh control.');
$BSN->makeAccountById($holder)->addBalanceRecord('MTLAP', 5);
$above_threshold = json_decode($Controller->Token('TEST-' . $issuer), true, 512, JSON_THROW_ON_ERROR);
assertTokenCache($above_threshold['can_refresh'], 'A signer with more than 4 MTLAP must retain refresh access.');
$BSN->makeAccountById($holder)->addBalanceRecord('MTLAP', 4);
$_GET['refresh_data_code'] = $authorized['code'];
assertTokenCache($Controller->isRefreshDataRequested('token_data:' . $issuer . ':TEST'), 'Matching token and session must authorize refresh.');
assertTokenCache(!$Controller->isRefreshDataRequested('token_data:' . $other_issuer . ':TEST'), 'Refresh codes must be bound to the exact token issuer.');
assertTokenCache(str_contains($authorized['action'], 'current_account=' . $issuer), 'Refresh must preserve the selected account.');

class TokenCacheRedirect extends RuntimeException {}

$Response = new class extends \Pecee\Http\Response {
    public function __construct() {}
    public function redirect(string $url, ?int $httpCode = null): never { throw new TokenCacheRedirect($url); }
};
(new ReflectionProperty(\Pecee\SimpleRouter\SimpleRouter::class, 'response'))->setValue(null, $Response);
$refresh_page = static function () use ($Controller, $issuer): string {
    try {
        $Controller->Token('TEST-' . $issuer);
    } catch (TokenCacheRedirect $Redirect) {
        return $Redirect->getMessage();
    }
    throw new RuntimeException('Authorized refresh must redirect after loading data.');
};
$_SERVER['REQUEST_URI'] .= '&refresh_data_code=' . $authorized['code'];
$Mock->append($page([$asset('50')]), $page([$account('50')]));
$redirect_url = $refresh_page();
assertTokenCache($Cache->entries[$cache_key]['data']['asset']['issued'] === 50.0, 'The page refresh action must actually bypass the cache.');
assertTokenCache(!str_contains($redirect_url, 'refresh_data_code') && str_contains($redirect_url, 'current_account=' . $issuer), 'Redirect must remove the refresh code while preserving account context.');
$Mock->append(new Response(503));
$redirect_url = $refresh_page();
assertTokenCache(str_contains($redirect_url, 'refresh_status=failed'), 'Refresh failure must survive the redirect to the page.');
assertTokenCache($Cache->entries[$cache_key]['data']['asset']['issued'] === 50.0, 'Failed page refresh must preserve the saved data.');

$BSN->makeAccountById($holder)->addBalanceRecord('MTLAP', 3.9999999);
$revoked = json_decode($Controller->Token('TEST-' . $issuer), true, 512, JSON_THROW_ON_ERROR);
assertTokenCache(!$revoked['can_refresh'] && $revoked['code'] === null, 'A previously valid refresh URL must stop working when the signer drops below 4 MTLAP.');
assertTokenCache($Cache->entries[$cache_key]['data']['asset']['issued'] === 50.0, 'Rejected refresh must leave the cached data intact.');

$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.ru.yaml', 'ru');
$Twig = new Environment(new ChainLoader([
    new ArrayLoader(['page.twig' => '{% block content %}{% endblock %}']),
    new FilesystemLoader(dirname(__DIR__) . '/twig'),
]));
$Twig->addExtension(new TwigExtension($Translator, new AssetVersions(dirname(__DIR__))));
$Twig->addExtension(new TranslationExtension($Translator));
$render = static function (array $snapshot, array $refresh) use ($Twig, $issuer): DOMXPath {
    $html = $Twig->render('token.twig', [
        'code' => 'TEST', 'issuer' => ['id' => $issuer, 'display_name' => 'Test issuer'],
        'token_snapshot' => $snapshot, 'token_refresh' => $refresh,
        'issued' => $snapshot['asset']['issued'] ?? null,
        'holders_count' => $snapshot['asset']['holders_count'] ?? null,
    ]);
    assertTokenCache(!str_contains($html, 'cache_status.'), 'Cache controls must be translated in both languages.');
    $Dom = new DOMDocument();
    @$Dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    return new DOMXPath($Dom);
};
foreach (['en', 'ru'] as $locale) {
    $Translator->setLocale($locale);
    $XPath = $render($stale, $authorized);
    $href = $XPath->evaluate('string(//a[@data-cache-refresh]/@href)');
    parse_str(parse_url($href, PHP_URL_QUERY), $query);
    assertTokenCache($query['current_account'] === $issuer && $query['open_trustline'] === 'no' && $query['refresh_data_code'] === $authorized['code'], 'Refresh link must retain query parameters and the scoped code.');
    assertTokenCache($XPath->query('//dt//a[@data-cache-refresh and @aria-label]//i[contains(@class, "fa-arrows-rotate")]')->length === 1, 'Eligible users must receive an accessible refresh icon beside the token figures.');
    assertTokenCache($XPath->evaluate('normalize-space(//a[@data-cache-refresh])') === '', 'The compact refresh control must contain only the icon.');
    assertTokenCache($XPath->query('//*[@role="status"]')->length === 1, 'Failed refresh must visibly explain the fallback.');
    foreach ([$anonymous, $below_threshold, $revoked, $authorized, $above_threshold] as $refresh) {
        foreach ([$first, $stale, $unavailable] as $snapshot) {
            $XPath = $render($snapshot, $refresh);
            assertTokenCache($XPath->query('//dt//span[@data-cache-age]')->length === 1, 'Every visitor must see the data age beside the token figures.');
            assertTokenCache($XPath->evaluate('normalize-space(//span[@data-cache-age])') === $Twig->getExtension(TwigExtension::class)->period($snapshot['fetched_at']), 'Age must describe the snapshot, including unavailable data.');
            assertTokenCache($XPath->query('//a[@data-cache-refresh]')->length === (int) $refresh['can_refresh'], 'Only eligible signers may receive a refresh link.');
            assertTokenCache($XPath->query('//*[@role="status"]')->length === (int) $snapshot['warning'], 'Refresh failures must be visible to everyone, without warnings on a successful load.');
            if ($snapshot['warning']) {
                $key = $snapshot['fetched_at'] ? 'cache_status.fallback' : 'cache_status.failed';
                assertTokenCache($XPath->evaluate('normalize-space(//*[@role="status"])') === $Translator->trans($key), 'Warnings must distinguish saved values from unavailable data.');
            }
        }
        $XPath = $render($first, array_replace($refresh, ['status' => 'failed']));
        assertTokenCache($XPath->query('//*[@role="status"]')->length === 1, 'A failed manual refresh must remain visible after the redirect for every visitor.');
    }
}

fwrite(STDOUT, "Token data cache tests passed.\n");
