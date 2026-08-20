<?php

declare(strict_types=1);

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use Montelibero\BSN\ApiKeysManager;
use Montelibero\BSN\ApiTokenDigest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$action = $_SERVER['argv'][1] ?? '';
if (!is_string($action) || !in_array($action, ['seed', 'verify', 'seed-invalid', 'verify-invalid'], true)) {
    fwrite(STDERR, "Usage: php tools/ci/api-key-digest-smoke.php seed|verify|seed-invalid|verify-invalid\n");
    exit(2);
}

$database = $_ENV['MONGO_BASENAME'] ?? '';
if (
    !is_string($database)
    || (!str_starts_with($database, 'api_key_smoke_') && !str_starts_with($database, 'api_key_purge_guard_'))
) {
    throw new RuntimeException('The digest smoke test only runs against a disposable API key test database.');
}

$host = $_ENV['MONGO_HOST'] ?? '127.0.0.1';
$port = $_ENV['MONGO_PORT'] ?? '27017';
$username = $_ENV['MONGO_ROOT_USERNAME'] ?? '';
$password = $_ENV['MONGO_ROOT_PASSWORD'] ?? '';
$auth_source = $_ENV['MONGO_AUTH_SOURCE'] ?? 'admin';
$uri = $username === ''
    ? sprintf('mongodb://%s:%s/', $host, $port)
    : sprintf('mongodb://%s:%s@%s:%s/?authSource=%s', $username, $password, $host, $port, $auth_source);

$manager = new Manager($uri);
$namespace = $database . '.api_keys';

if ($action === 'seed-invalid') {
    $manager->executeCommand($database, new Command([
        'createIndexes' => 'api_keys',
        'indexes' => [
            ['key' => ['key' => 1], 'name' => 'uniq_key', 'unique' => true],
            ['key' => ['key_digest' => 1], 'name' => 'uniq_key_digest', 'unique' => true, 'sparse' => true],
        ],
    ]));

    $bulk = new BulkWrite();
    $bulk->insert([
        'account_id' => 'GINVALID',
        'name' => 'mismatched raw and digest',
        'key' => str_repeat('5', 48),
        'key_digest' => ApiTokenDigest::fromToken(str_repeat('6', 48)),
        'key_digest_algorithm' => ApiTokenDigest::ALGORITHM,
        'permissions' => ['contacts' => ['read' => true]],
    ]);
    $manager->executeBulkWrite($namespace, $bulk);

    fwrite(STDOUT, "Invalid API key purge guard fixture seeded.\n");
    exit(0);
}

if ($action === 'verify-invalid') {
    $documents = $manager->executeQuery($namespace, new Query([]))->toArray();
    if (count($documents) !== 1 || !property_exists($documents[0], 'key')) {
        throw new RuntimeException('The rejected purge changed its invalid raw-key fixture.');
    }

    $indexes = $manager->executeCommand($database, new Command(['listIndexes' => 'api_keys']))->toArray();
    $index_names = array_map(static fn (object $index): string => (string) ($index->name ?? ''), $indexes);
    if (!in_array('uniq_key', $index_names, true)) {
        throw new RuntimeException('The rejected purge dropped the legacy index before validation completed.');
    }

    fwrite(STDOUT, "Invalid API key purge was rejected without changes.\n");
    exit(0);
}

if ($action === 'seed') {
    $existing = $manager->executeQuery($namespace, new Query([], ['limit' => 1]))->toArray();
    if ($existing !== []) {
        throw new RuntimeException('The disposable API key smoke collection is not empty.');
    }

    $manager->executeCommand($database, new Command([
        'createIndexes' => 'api_keys',
        'indexes' => [
            ['key' => ['key' => 1], 'name' => 'uniq_key', 'unique' => true],
            ['key' => ['key_digest' => 1], 'name' => 'uniq_key_digest', 'unique' => true, 'sparse' => true],
        ],
    ]));

    $bulk = new BulkWrite();
    $bulk->insert([
        'account_id' => 'GTEST1',
        'name' => 'raw only',
        'key' => str_repeat('1', 48),
        'permissions' => ['contacts' => ['read' => true]],
    ]);
    $bulk->insert([
        'account_id' => 'GTEST2',
        'name' => 'raw and digest',
        'key' => str_repeat('2', 48),
        'key_digest' => ApiTokenDigest::fromToken(str_repeat('2', 48)),
        'key_digest_algorithm' => ApiTokenDigest::ALGORITHM,
        'permissions' => ['contacts' => ['read' => true]],
    ]);
    $bulk->insert([
        'account_id' => 'GTEST3',
        'name' => 'digest only',
        'key_digest' => ApiTokenDigest::fromToken(str_repeat('3', 48)),
        'key_digest_algorithm' => ApiTokenDigest::ALGORITHM,
        'permissions' => ['contacts' => ['read' => true]],
    ]);
    $manager->executeBulkWrite($namespace, $bulk);

    fwrite(STDOUT, "Temporary API key digest fixtures seeded.\n");
    exit(0);
}

$documents = $manager->executeQuery($namespace, new Query([]))->toArray();
if (count($documents) !== 3) {
    throw new RuntimeException('The digest migration changed the number of API key documents.');
}
foreach ($documents as $document) {
    if (!ApiTokenDigest::isValid($document->key_digest ?? null)) {
        throw new RuntimeException('An API key document is missing its digest after migration.');
    }
    if (($document->key_digest_algorithm ?? null) !== ApiTokenDigest::ALGORITHM) {
        throw new RuntimeException('An API key document is missing its digest algorithm after migration.');
    }
    if (property_exists($document, 'key')) {
        throw new RuntimeException('An API key document still contains a raw secret after purge.');
    }
}

$indexes = $manager->executeCommand($database, new Command(['listIndexes' => 'api_keys']))->toArray();
$index_names = array_map(static fn (object $index): string => (string) ($index->name ?? ''), $indexes);
if (in_array('uniq_key', $index_names, true)) {
    throw new RuntimeException('The legacy raw-key index still exists after purge.');
}
if (!in_array('uniq_key_digest', $index_names, true)) {
    throw new RuntimeException('The digest index is missing after purge.');
}

$Keys = new ApiKeysManager($manager, $database);
if (($Keys->findByKey(str_repeat('1', 48))['account_id'] ?? null) !== 'GTEST1') {
    throw new RuntimeException('Digest lookup failed after backfill.');
}
if (($Keys->findByKey(str_repeat('3', 48))['account_id'] ?? null) !== 'GTEST3') {
    throw new RuntimeException('Digest-only lookup failed.');
}

$created = $Keys->createKey(
    'GTEST4',
    'digest-only creation',
    ['contacts' => ['read' => true]],
);
$created_token = $created['key'] ?? null;
if (!is_string($created_token) || strlen($created_token) !== 48) {
    throw new RuntimeException('A newly created key was not returned exactly once to the caller.');
}
$created_documents = $manager->executeQuery(
    $namespace,
    new Query(['key_digest' => ApiTokenDigest::fromToken($created_token)], ['limit' => 1])
)->toArray();
$created_document = current($created_documents);
if (!$created_document || property_exists($created_document, 'key')) {
    throw new RuntimeException('A newly created API key was persisted as a raw secret.');
}
if (($Keys->findByKey($created_token)['account_id'] ?? null) !== 'GTEST4') {
    throw new RuntimeException('A newly created digest-only key cannot authenticate.');
}

fwrite(STDOUT, "Temporary Mongo API key raw-secret purge smoke passed.\n");
