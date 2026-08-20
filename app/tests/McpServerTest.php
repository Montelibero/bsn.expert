<?php

declare(strict_types=1);

use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\KnownTagsCatalog;
use Montelibero\BSN\Knowledge\AccountReportBuilder;
use Montelibero\BSN\Mcp\McpBsnTools;
use Montelibero\BSN\Mcp\McpServer;
use Montelibero\BSN\RequestLocale;
use Soneso\StellarSDK\Crypto\KeyPair;

require dirname(__DIR__) . '/vendor/autoload.php';

final class McpTestAccountsManager extends AccountsManager
{
    /** @param array<string, string> $usernames */
    public function __construct(private readonly array $usernames)
    {
    }

    public function fetchUsernames(): array
    {
        return $this->usernames;
    }

    public function fetchAccountIdByUsername(string $username): ?string
    {
        foreach ($this->usernames as $account_id => $known_username) {
            if (strcasecmp($known_username, $username) === 0) {
                return $account_id;
            }
        }

        return null;
    }
}

final class McpTestDocumentsManager extends DocumentsManager
{
    public function __construct()
    {
    }

    public function getDocuments(?string $source = null): array
    {
        return [];
    }
}

function assertMcpSame(mixed $expected, mixed $actual, string $message): void
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

/** @param array<string, mixed> $groups @return array<string, mixed> */
function mcpTagGroup(array $groups, string $name): array
{
    foreach ($groups as $group) {
        if (($group['name'] ?? null) === $name) {
            return $group;
        }
    }

    throw new RuntimeException(sprintf('Tag group %s was not returned.', $name));
}

/** @param array<string, mixed> $tags @return array<string, mixed> */
function mcpCatalogTag(array $tags, string $name): array
{
    foreach ($tags as $tag) {
        if (($tag['name'] ?? null) === $name) {
            return $tag;
        }
    }

    throw new RuntimeException(sprintf('Catalog tag %s was not returned.', $name));
}

/** @return array<string, mixed> */
function mcpResultBody(array $response): array
{
    $body = $response['body'] ?? null;
    if (!is_array($body)) {
        throw new RuntimeException('MCP response body is missing.');
    }

    return $body;
}

$Alice = KeyPair::random()->getAccountId();
$Bob = KeyPair::random()->getAccountId();
$Carol = KeyPair::random()->getAccountId();
$Unknown = KeyPair::random()->getAccountId();
$Accounts = new McpTestAccountsManager([$Alice => 'alice']);
$Catalog = new KnownTagsCatalog(new RequestLocale(), dirname(__DIR__) . '/known_tags');
$BSN = new BSN($Accounts, new McpTestDocumentsManager());
$BSN->loadKnownTags($Catalog->list());
$BSN->loadFromJson([
    'createDate' => '2026-08-20T10:00:00+00:00',
    'accounts' => [
        $Alice => [
            'profile' => [
                'Name' => ['Alice Example'],
                'About' => ['A public account used by the MCP test.'],
                'Website' => ['https://example.com/alice'],
            ],
            'balances' => [
                'MTLAP' => 2,
                'EURMTL' => 12.5,
            ],
            'tags' => [
                'Spouse' => [$Bob, $Carol],
                'Employer' => [$Bob],
                'Like' => [$Bob],
                'MysteryTag' => [$Bob],
            ],
        ],
        $Bob => [
            'profile' => ['Name' => ['Bob Example']],
            'tags' => [
                'Spouse' => [$Alice],
                'Employee' => [$Alice],
            ],
        ],
        $Carol => [
            'profile' => ['Name' => ['Carol Example']],
        ],
    ],
]);

$Tools = new McpBsnTools($BSN, $Accounts, $Catalog, new AccountReportBuilder($BSN, $Catalog));
$Server = new McpServer($Tools);

$initialize = $Server->handle([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => McpServer::LATEST_LEGACY_VERSION,
        'capabilities' => [],
        'clientInfo' => ['name' => 'test', 'version' => '1'],
    ],
]);
$initialize_body = mcpResultBody($initialize);
assertMcpSame(200, $initialize['status'], 'Legacy initialization must succeed.');
assertMcpSame(
    McpServer::LATEST_LEGACY_VERSION,
    $initialize_body['result']['protocolVersion'] ?? null,
    'Legacy initialization must negotiate the requested version.',
);
assertMcpSame(
    McpServer::SERVER_NAME,
    $initialize_body['result']['serverInfo']['name'] ?? null,
    'Legacy initialization must identify the MCP server.',
);

$discover_message = [
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'server/discover',
    'params' => [
        '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => McpServer::MODERN_VERSION,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => [],
        ],
    ],
];
$discover = $Server->handle(
    $discover_message,
    McpServer::MODERN_VERSION,
    'server/discover',
);
$discover_body = mcpResultBody($discover);
assertMcpSame(
    true,
    in_array(McpServer::MODERN_VERSION, $discover_body['result']['supportedVersions'] ?? [], true)
        && in_array(McpServer::LATEST_LEGACY_VERSION, $discover_body['result']['supportedVersions'] ?? [], true),
    'A dual-era discovery result must advertise both modern and legacy protocol versions.',
);
assertMcpSame(
    McpServer::SERVER_NAME,
    $discover_body['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? null,
    'Every modern result must carry server identity metadata.',
);

$missing_protocol_header = $Server->handle($discover_message, null, 'server/discover');
assertMcpSame(400, $missing_protocol_header['status'], 'A modern body without its protocol header must be rejected.');
assertMcpSame(
    -32020,
    mcpResultBody($missing_protocol_header)['error']['code'] ?? null,
    'A missing mirrored protocol header must use HeaderMismatch.',
);

$header_mismatch = $Server->handle(
    $discover_message,
    McpServer::MODERN_VERSION,
    'tools/list',
);
assertMcpSame(400, $header_mismatch['status'], 'Modern header mismatches must be rejected at HTTP level.');
assertMcpSame(
    -32020,
    mcpResultBody($header_mismatch)['error']['code'] ?? null,
    'Modern header mismatches must use the MCP HeaderMismatch code.',
);

$tools_list = $Server->handle(
    [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/list',
        'params' => ['_meta' => $discover_message['params']['_meta']],
    ],
    McpServer::MODERN_VERSION,
    'tools/list',
);
$tools_list_body = mcpResultBody($tools_list);
assertMcpSame(
    [McpBsnTools::ACCOUNT_GET, McpBsnTools::ACCOUNT_TAGS, McpBsnTools::TAGS_LIST],
    array_column($tools_list_body['result']['tools'] ?? [], 'name'),
    'The demo must expose exactly three read-only tools.',
);
assertMcpSame('complete', $tools_list_body['result']['resultType'] ?? null, 'Modern tool lists must be complete results.');
assertMcpSame('public', $tools_list_body['result']['cacheScope'] ?? null, 'The anonymous tool list may be cached publicly.');

$account_call = $Server->handle(
    [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => [
            'name' => McpBsnTools::ACCOUNT_GET,
            'arguments' => ['account' => '@ALICE', 'locale' => 'en'],
            '_meta' => $discover_message['params']['_meta'],
        ],
    ],
    McpServer::MODERN_VERSION,
    'tools/call',
    McpBsnTools::ACCOUNT_GET,
);
$account_body = mcpResultBody($account_call);
$account = $account_body['result']['structuredContent'] ?? [];
assertMcpSame(false, $account_body['result']['isError'] ?? null, 'A username account lookup must succeed.');
assertMcpSame($Alice, $account['account']['id'] ?? null, 'A username must resolve to its Stellar account ID.');
assertMcpSame('username', $account['lookup']['resolved_by'] ?? null, 'The lookup result must explain username resolution.');
assertMcpSame('person', $account['account']['relation']['type'] ?? null, 'MTLAP must produce the established person relation.');
assertMcpSame(2, $account['account']['relation']['level'] ?? null, 'Membership level must be exposed.');
assertMcpSame(12.5, $account['balances']['EURMTL'] ?? null, 'Public snapshot balances must be exposed.');
assertMcpSame(false, array_key_exists('relations', $account), 'A general lookup must not return full tag relations.');
assertMcpSame(true, $account['tags_summary']['known_tags_only'] ?? null, 'The tag summary must state its known-only scope.');
assertMcpSame(
    [
        'links_count' => 2,
        'tags_count' => 2,
        'tag_names' => ['Spouse', 'Employee'],
    ],
    $account['tags_summary']['incoming'] ?? null,
    'A general lookup must summarize known incoming tags without linked accounts.',
);
assertMcpSame(
    [
        'links_count' => 4,
        'tags_count' => 3,
        'tag_names' => ['Like', 'Spouse', 'Employer'],
    ],
    $account['tags_summary']['outgoing'] ?? null,
    'A general lookup must summarize known outgoing tags and exclude unknown tags.',
);
assertMcpSame(
    McpBsnTools::ACCOUNT_TAGS,
    $account['tags_summary']['details']['tool'] ?? null,
    'A compact tag summary must point clients to the detailed tag tool.',
);
assertMcpSame(
    ['account' => $Alice, 'locale' => 'en'],
    $account['tags_summary']['details']['arguments'] ?? null,
    'The detailed tag hint must provide canonical reusable arguments.',
);
assertMcpSame(
    strtotime('2026-08-20T10:00:00+00:00'),
    $account['source']['snapshot_at'] ?? null,
    'Account results must identify their source snapshot.',
);

$tags_call = $Server->handle([
    'jsonrpc' => '2.0',
    'id' => 5,
    'method' => 'tools/call',
    'params' => [
        'name' => McpBsnTools::ACCOUNT_TAGS,
        'arguments' => ['account' => $Alice, 'locale' => 'ru'],
    ],
]);
$account_tags = mcpResultBody($tags_call)['result']['structuredContent'] ?? [];
$spouse = mcpTagGroup($account_tags['outgoing']['groups'] ?? [], 'Spouse');
assertMcpSame('same_tag', $spouse['pair']['kind'] ?? null, 'Spouse must be represented as a same-tag pair.');
assertMcpSame(true, $spouse['pair']['strong'] ?? null, 'Spouse must retain its strong-pair rule.');
$spouse_statuses = array_column($spouse['links'] ?? [], 'reciprocal_status');
sort($spouse_statuses);
assertMcpSame(
    ['confirmed', 'required_missing'],
    $spouse_statuses,
    'Each Spouse link must report whether its reciprocal side exists.',
);
$employer = mcpTagGroup($account_tags['outgoing']['groups'] ?? [], 'Employer');
assertMcpSame('Employee', $employer['pair']['name'] ?? null, 'Employer must point to the complementary Employee tag.');
assertMcpSame('confirmed', $employer['links'][0]['reciprocal_status'] ?? null, 'Employer/Employee must be recognized as reciprocal.');
$like = mcpTagGroup($account_tags['outgoing']['groups'] ?? [], 'Like');
assertMcpSame('missing', $like['links'][0]['reciprocal_status'] ?? null, 'An optional missing pair must not be marked required.');
$mystery = mcpTagGroup($account_tags['outgoing']['groups'] ?? [], 'MysteryTag');
assertMcpSame(false, $mystery['known'] ?? null, 'Unknown snapshot tags must remain visible and marked unknown.');
assertMcpSame('not_applicable', $mystery['links'][0]['reciprocal_status'] ?? null, 'An unpaired unknown tag has no reciprocal requirement.');

$catalog_call = $Server->handle([
    'jsonrpc' => '2.0',
    'id' => 6,
    'method' => 'tools/call',
    'params' => [
        'name' => McpBsnTools::TAGS_LIST,
        'arguments' => ['locale' => 'ru'],
    ],
]);
$catalog = mcpResultBody($catalog_call)['result']['structuredContent'] ?? [];
$catalog_spouse = mcpCatalogTag($catalog['tags'] ?? [], 'Spouse');
assertMcpSame('same_tag', $catalog_spouse['pair']['kind'] ?? null, 'The catalog must explain symmetric pairs.');
$catalog_employer = mcpCatalogTag($catalog['tags'] ?? [], 'Employer');
assertMcpSame('complementary', $catalog_employer['pair']['kind'] ?? null, 'The catalog must explain complementary pairs.');
assertMcpSame('Employee', $catalog_employer['pair']['name'] ?? null, 'The complementary pair name must be explicit.');
$catalog_expert = mcpCatalogTag($catalog['tags'] ?? [], 'Expert');
assertMcpSame(null, $catalog_expert['pair'] ?? null, 'The catalog must expose non-paired tags.');
$catalog_mystery = mcpCatalogTag($catalog['tags'] ?? [], 'MysteryTag');
assertMcpSame(false, $catalog_mystery['known'] ?? null, 'The catalog must include unknown tags observed in the snapshot.');

$unknown_account_call = $Server->handle([
    'jsonrpc' => '2.0',
    'id' => 7,
    'method' => 'tools/call',
    'params' => [
        'name' => McpBsnTools::ACCOUNT_GET,
        'arguments' => ['account' => $Unknown],
    ],
]);
$unknown_account = mcpResultBody($unknown_account_call)['result']['structuredContent'] ?? [];
assertMcpSame(false, $unknown_account['account']['is_known_in_bsn'] ?? null, 'A valid absent Stellar account must be a successful empty lookup.');
assertMcpSame(
    ['links_count' => 0, 'tags_count' => 0, 'tag_names' => []],
    $unknown_account['tags_summary']['incoming'] ?? null,
    'An absent account must have an explicit empty incoming tag summary.',
);
assertMcpSame(
    ['links_count' => 0, 'tags_count' => 0, 'tag_names' => []],
    $unknown_account['tags_summary']['outgoing'] ?? null,
    'An absent account must have an explicit empty outgoing tag summary.',
);

$invalid_account_call = $Server->handle([
    'jsonrpc' => '2.0',
    'id' => 8,
    'method' => 'tools/call',
    'params' => [
        'name' => McpBsnTools::ACCOUNT_GET,
        'arguments' => ['account' => 'not an account'],
    ],
]);
assertMcpSame(
    true,
    mcpResultBody($invalid_account_call)['result']['isError'] ?? null,
    'Bad user input must be returned as a tool-level error.',
);

$unknown_tool = $Server->handle([
    'jsonrpc' => '2.0',
    'id' => 9,
    'method' => 'tools/call',
    'params' => [
        'name' => 'bsn.unknown',
        'arguments' => [],
    ],
]);
assertMcpSame(-32602, mcpResultBody($unknown_tool)['error']['code'] ?? null, 'Unknown tools must be protocol errors.');

$notification = $Server->handle([
    'jsonrpc' => '2.0',
    'method' => 'notifications/initialized',
]);
assertMcpSame(202, $notification['status'], 'Legacy notifications must be accepted without a response body.');
assertMcpSame(null, $notification['body'], 'Notifications must not receive JSON-RPC responses.');

fwrite(STDOUT, "MCP server regression test passed.\n");
