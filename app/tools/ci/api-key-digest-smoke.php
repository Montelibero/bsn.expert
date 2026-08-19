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
if (!is_string($action) || !in_array($action, ['seed', 'verify'], true)) {
    fwrite(STDERR, "Usage: php tools/ci/api-key-digest-smoke.php seed|verify\n");
    exit(2);
}

$database = $_ENV['MONGO_BASENAME'] ?? '';
if (!is_string($database) || !str_starts_with($database, 'api_key_smoke_')) {
    throw new RuntimeException('The digest smoke test only runs against a disposable api_key_smoke_* database.');
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
}

$Keys = new ApiKeysManager($manager, $database);
if (($Keys->findByKey(str_repeat('1', 48))['account_id'] ?? null) !== 'GTEST1') {
    throw new RuntimeException('Digest lookup failed after backfill.');
}
if (($Keys->findByKey(str_repeat('3', 48))['account_id'] ?? null) !== 'GTEST3') {
    throw new RuntimeException('Digest-only lookup failed.');
}

$bulk = new BulkWrite();
$bulk->insert([
    'account_id' => 'GTEST4',
    'name' => 'legacy raw fallback',
    'key' => str_repeat('4', 48),
    'permissions' => ['contacts' => ['read' => true]],
]);
$manager->executeBulkWrite($namespace, $bulk);
if (($Keys->findByKey(str_repeat('4', 48))['account_id'] ?? null) !== 'GTEST4') {
    throw new RuntimeException('Legacy raw lookup fallback failed.');
}

fwrite(STDOUT, "Temporary Mongo API key digest smoke passed.\n");
