<?php

declare(strict_types=1);

use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\ApiKeysManager;
use Montelibero\BSN\ApiTokenAuthenticator;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\ApiController;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\GristSnapshotStore;
use Montelibero\BSN\GristSyncService;
use Montelibero\BSN\MTLA\MtlaMembersService;
use Montelibero\BSN\RequestSession;
use Soneso\StellarSDK\Crypto\KeyPair;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();
require dirname(__DIR__) . '/vendor/autoload.php';

function assertMtlaMembers(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function gristRequest(string $url, string $method): array
{
    return ['records' => $GLOBALS['grist_records']];
}

$Accounts = new class extends AccountsManager {
    public function __construct() {}
    public function fetchUsernames(): array { return []; }
};
$Documents = new class extends DocumentsManager {
    public function __construct() {}
    public function getDocuments(?string $source = null): array { return []; }
};
$Snapshots = new class extends GristSnapshotStore {
    public ?array $snapshot = null;
    public bool $fail = false;
    public function __construct() {}
    public function fetch(string $scope): ?array
    {
        if ($this->fail) {
            throw new MongoDB\Driver\Exception\RuntimeException('Test snapshot failure');
        }
        return $this->snapshot;
    }
    public function store(string $scope, array $data): array
    {
        return $this->snapshot = ['scope' => $scope, 'version' => 1, 'data' => $data, 'updated_at_ts' => time()];
    }
};
$Keys = new class extends ApiKeysManager {
    public array $keys = [];
    public int $created = 0;
    public function __construct() {}
    public function findByKey(string $key): ?array { return $this->keys[$key] ?? null; }
    public function markUsed(string $id, string $ip): void {}
    public function getKeysByAccount(string $account_id): array
    {
        return array_values(array_filter($this->keys, static fn(array $key): bool => $key['account_id'] === $account_id));
    }
    public function createKey(string $account_id, string $name, array $permissions): array
    {
        $id = 'created-' . ++$this->created;
        return $this->keys[$id] = compact('account_id', 'name', 'permissions', 'id') + ['key' => $id];
    }
};

[$coordinator, $other, $member, $former, $program, $second_program] = array_map(
    static fn(): string => KeyPair::random()->getAccountId(), range(1, 6)
);
$graph = ['accounts' => [
    MtlaController::MTLA_ACCOUNT => ['tags' => ['Program' => [$program]]],
    $program => ['tags' => ['ProgramCoordinator' => [$coordinator], 'MyPart' => [$coordinator]]],
    $second_program => ['tags' => ['ProgramCoordinator' => [$coordinator], 'MyPart' => [$coordinator]]],
    $coordinator => ['balances' => ['MTLAP' => 1], 'tags' => ['PartOf' => [$program, $second_program]]],
    $other => ['balances' => ['MTLAP' => 1]],
    $member => ['balances' => ['MTLAP' => 2]],
    $former => ['balances' => ['MTLAP' => 0]],
]];
$BSN = new BSN($Accounts, $Documents);
$BSN->loadFromJson($graph);
$Service = new MtlaMembersService($BSN, $Snapshots);
assertMtlaMembers(true, $Service->isCoordinator($coordinator), 'A confirmed coordinator must qualify.');
assertMtlaMembers(false, $Service->isCoordinator($program), 'The program account must not inherit coordinator access.');

$invalid_graphs = [];
$invalid_graphs['program cancelled'] = $graph;
$invalid_graphs['program cancelled']['accounts'][MtlaController::MTLA_ACCOUNT]['tags']['Program'] = [];
$invalid_graphs['coordinator removed'] = $graph;
$invalid_graphs['coordinator removed']['accounts'][$program]['tags']['ProgramCoordinator'] = [];
$invalid_graphs['membership lost'] = $graph;
$invalid_graphs['membership lost']['accounts'][$coordinator]['balances']['MTLAP'] = 0;
$invalid_graphs['missing program participation'] = $graph;
$invalid_graphs['missing program participation']['accounts'][$program]['tags']['MyPart'] = [];
$invalid_graphs['missing reciprocal participation'] = $graph;
$invalid_graphs['missing reciprocal participation']['accounts'][$coordinator]['tags']['PartOf'] = [];
$invalid_graphs['multiple nominees including ineligible person'] = $graph;
$invalid_graphs['multiple nominees including ineligible person']['accounts'][$program]['tags']['ProgramCoordinator'][] = $former;
foreach ($invalid_graphs as $reason => $invalid_graph) {
    $BSN->loadFromJson($invalid_graph);
    assertMtlaMembers(false, $Service->isCoordinator($coordinator), 'Access must fail after ' . $reason);
}
$multiple = $invalid_graphs['multiple nominees including ineligible person'];
$multiple['accounts'][$former] = ['balances' => ['MTLAP' => 1], 'tags' => ['PartOf' => [$program]]];
$multiple['accounts'][$program]['tags']['MyPart'][] = $former;
$BSN->loadFromJson($multiple);
assertMtlaMembers(false, $Service->isCoordinator($coordinator), 'Two valid nominees must both be rejected.');
assertMtlaMembers(false, $Service->isCoordinator($former), 'The second valid nominee must also be rejected.');
$multiple['accounts'][MtlaController::MTLA_ACCOUNT]['tags']['Program'][] = $second_program;
$BSN->loadFromJson($multiple);
assertMtlaMembers(true, $Service->isCoordinator($coordinator), 'A different valid program must preserve access.');
assertMtlaMembers(false, $Service->isCoordinator($former), 'Another person must not gain access from that program.');
$duplicate = $graph;
$duplicate['accounts'][$program]['tags']['ProgramCoordinator'][] = $coordinator;
$BSN->loadFromJson($duplicate);
assertMtlaMembers(true, $Service->isCoordinator($coordinator), 'Repeated links to the same nominee are not multiple coordinators.');
$BSN->loadFromJson($graph);

// Grist supplies identities, while BSN alone decides current membership.
$GLOBALS['grist_records'] = [
    ['fields' => ['Stellar' => $coordinator, 'TGID' => 123, 'Telegram' => '@coordinator', 'MTLAP' => 0]],
    ['fields' => ['Stellar' => $member, 'TGID' => 123.0, 'Telegram' => '']],
    ['fields' => ['Stellar' => $former, 'TGID' => 456, 'Telegram' => 'former', 'MTLAP' => 1]],
    ['fields' => ['Stellar' => $other, 'TGID' => '9007199254740993', 'Telegram' => 'other']],
];
(new GristSyncService($BSN, $Documents, $Snapshots))->syncMtlaMembers();
assertMtlaMembers('4503599627370495', GristSyncService::normalizeTelegramId(4503599627370495.0), 'Integral Grist numbers must not become scientific notation.');
assertMtlaMembers(null, GristSyncService::normalizeTelegramId(123.5), 'Fractional IDs must be rejected.');
assertMtlaMembers(4, count($Snapshots->snapshot['data']), 'Grist must not prefilter membership.');
$members = $Service->members();
assertMtlaMembers(3, count($members), 'Only current BSN members must be returned, including members of no program.');
assertMtlaMembers(['telegram_id' => '123', 'telegram_username' => 'coordinator'], $members[$coordinator], 'Telegram data must be normalized.');
assertMtlaMembers(null, $members[$member]['telegram_username'], 'A missing username must be null.');
assertMtlaMembers('9007199254740993', $members[$other]['telegram_id'], 'Large Telegram IDs must remain strings.');
assertMtlaMembers(2, count($Service->members(null, '123')), 'Telegram lookup must return all matching Stellar accounts.');
assertMtlaMembers([$member => $members[$member]], $Service->members($member), 'Stellar lookup must keep the same keyed format.');
assertMtlaMembers([], $Service->members($member, '456'), 'Combined filters must use AND.');
$Snapshots->snapshot['data'][] = ['stellar' => $member, 'tg_id' => '999', 'tg_username' => 'conflict'];
assertMtlaMembers(false, isset($Service->members()[$member]), 'Conflicting identities must not be resolved by row order.');
array_pop($Snapshots->snapshot['data']);

$BSN->loadMtlaMembersFromJson($Snapshots->snapshot['data']);
assertMtlaMembers(null, $BSN->getAccountById($former)->getTelegramId(), 'The expanded import must not expose former members in existing UI.');
assertMtlaMembers('123', $BSN->getAccountById($coordinator)->getTelegramId(), 'Current members must retain Telegram metadata.');
$BSN->loadMtlaMembersFromJson([]);
assertMtlaMembers(null, $BSN->getAccountById($coordinator)->getTelegramId(), 'Removing a shared Telegram ID must clear every linked account.');
assertMtlaMembers(null, $BSN->getAccountById($member)->getTelegramId(), 'All removed identities must be cleared.');

$Translator = new Translator('ru');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.ru.yaml', 'ru');
$Twig = new Environment(new ChainLoader([
    new ArrayLoader(['page.twig' => '{% block content %}{% endblock %}']),
    new FilesystemLoader(dirname(__DIR__) . '/twig'),
]));
$Twig->addExtension(new TranslationExtension($Translator));
$Session = new RequestSession(false);
$User = new CurrentUser($BSN, $Session);
$Controller = new ApiController($Twig, $Keys, new ApiTokenAuthenticator($Keys), $Translator, $User, $Session, $Service);
$Keys->keys['test-token'] = [
    'id' => 'test-key', 'account_id' => $coordinator, 'name' => 'Members integration',
    'permissions' => ['contacts' => ['read' => true], 'mtla_members' => ['read' => true]],
];

$request = static function (array $query = [], ?string $token = 'test-token') use ($Controller): array {
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    http_response_code(200);
    $body = json_decode($Controller->MtlaMembers(), false, 512, JSON_THROW_ON_ERROR);
    return [http_response_code(), $body];
};
assertMtlaMembers(401, $request([], null)[0], 'Missing bearer authorization must fail.');
assertMtlaMembers(401, $request([], 'unknown')[0], 'Unknown API keys must fail.');
$_SESSION = ['account' => ['id' => $other], 'current_account_id' => $program];
assertMtlaMembers(200, $request()[0], 'Only the bearer owner must determine API access.');
unset($Keys->keys['test-token']['permissions']['mtla_members']);
assertMtlaMembers(403, $request()[0], 'Old keys must not acquire the new scope automatically.');
$Keys->keys['test-token']['permissions']['mtla_members'] = ['read' => false];
assertMtlaMembers(403, $request()[0], 'A disabled read permission must fail.');
$Keys->keys['test-token']['permissions']['mtla_members']['read'] = true;
$BSN->loadFromJson($invalid_graphs['program cancelled']);
assertMtlaMembers(403, $request()[0], 'The next request must deny a cancelled program.');
assertMtlaMembers(true, $Keys->keys['test-token']['permissions']['contacts']['read'], 'Other permissions must remain unchanged.');
$BSN->loadFromJson($graph);
assertMtlaMembers(200, $request()[0], 'Restoring coordinator status must restore the same key.');
$Keys->keys['test-token']['account_id'] = $other;
$_SESSION = ['account' => ['id' => $coordinator], 'current_account_id' => $coordinator];
assertMtlaMembers(403, $request(['current_account' => $coordinator])[0], 'Session or selected-account spoofing must not authorize an unrelated key.');
$Keys->keys['test-token']['account_id'] = $coordinator;

foreach ([['stellar' => 'invalid'], ['stellar' => []], ['telegram_id' => []], ['telegram_id' => ''], ['telegram_id' => '-1'], ['telegram_id' => '1e3']] as $query) {
    assertMtlaMembers(400, $request($query)[0], 'Malformed filters must fail.');
}
[$status, $body] = $request(['stellar' => $member, 'telegram_id' => '123']);
assertMtlaMembers(200, $status, 'Matching filters must succeed.');
assertMtlaMembers([$member], array_keys(get_object_vars($body->members)), 'Filtered responses must remain keyed by Stellar account.');
[$status, $body] = $request(['telegram_id' => '777']);
assertMtlaMembers(200, $status, 'No matches is a successful response.');
assertMtlaMembers(true, $body->members instanceof stdClass && get_object_vars($body->members) === [], 'Empty results must be an object, not a JSON array.');
$saved_snapshot = $Snapshots->snapshot;
$Snapshots->snapshot = null;
assertMtlaMembers(503, $request()[0], 'Missing source data must not appear to be an empty directory.');
$Snapshots->snapshot = $saved_snapshot;
$Snapshots->fail = true;
assertMtlaMembers(503, $request()[0], 'A failed source read must return a temporary error.');
$Snapshots->fail = false;

// Creation and the saved key list use the authenticated signer, never current_account.
$_SESSION = ['account' => ['id' => $other], 'current_account_id' => $coordinator];
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$html = $Controller->PreferencesApi();
assertMtlaMembers(false, str_contains($html, 'permissions[mtla_members][read]'), 'Non-coordinators must not see the new scope in the form.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'action' => 'create', 'name' => 'Forged scope',
    'csrf_token' => $Session->getOrCreateToken('csrf:api_keys'),
    'create_nonce' => $Session->get('api_key_create_nonce'),
    'permissions' => ['mtla_members' => ['read' => 'on']],
];
$Controller->PreferencesApi();
assertMtlaMembers(403, http_response_code(), 'A forged creation request must be denied.');
assertMtlaMembers(0, $Keys->created, 'Forbidden scopes must not create a key.');
$_SESSION = ['account' => ['id' => $coordinator], 'current_account_id' => $other];
$_SERVER['REQUEST_METHOD'] = 'GET';
$html = $Controller->PreferencesApi();
assertMtlaMembers(true, str_contains($html, 'permissions[mtla_members][read]'), 'Coordinators must see the new scope regardless of selected account.');
assertMtlaMembers(false, preg_match('/name="permissions\[mtla_members\]\[read\]"[^>]*checked/', $html) === 1, 'The new scope must default to off.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'action' => 'create', 'name' => 'Members only',
    'csrf_token' => $Session->getOrCreateToken('csrf:api_keys'),
    'create_nonce' => $Session->get('api_key_create_nonce'),
    'permissions' => ['mtla_members' => ['read' => 'on']],
];
$Controller->PreferencesApi();
assertMtlaMembers(1, $Keys->created, 'Coordinators must be able to create a members-only key.');
assertMtlaMembers(false, in_array(true, $Keys->keys['created-1']['permissions']['contacts'], true), 'Members-only keys must not gain contacts permissions.');
$BSN->loadFromJson($invalid_graphs['program cancelled']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$html = $Controller->PreferencesApi();
assertMtlaMembers(false, str_contains($html, 'permissions[mtla_members][read]'), 'Loss of status must hide the creation option.');
assertMtlaMembers(true, str_contains($html, 'Участники МТЛА'), 'Saved scopes must remain visible after loss of status.');
assertMtlaMembers(true, str_contains($html, 'После восстановления статуса'), 'The list must explain temporary suspension.');

$normalize = new ReflectionMethod(ApiKeysManager::class, 'normalizePermissions');
assertMtlaMembers(['read' => false], $normalize->invoke($Keys, [])['mtla_members'], 'Old keys must default to no members access.');
assertMtlaMembers(['read' => true], $normalize->invoke($Keys, ['mtla_members' => ['read' => true, 'write' => true]])['mtla_members'], 'Only the read action must be stored.');
$format = new ReflectionMethod(ApiKeysManager::class, 'formatKeyDoc');
$formatted = $format->invoke($Keys, (object) [
    '_id' => 'test-key', 'account_id' => $coordinator,
    'permissions' => (object) ['mtla_members' => (object) ['read' => true]],
]);
assertMtlaMembers(['read' => true], $formatted['permissions']['mtla_members'], 'Mongo subdocuments must be normalized to usable permission arrays.');

ob_end_clean();
fwrite(STDOUT, "MTLA members API, coordinator access and key UI tests passed.\n");
