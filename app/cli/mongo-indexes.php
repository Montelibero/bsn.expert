#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only run in CLI mode.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

if (empty($_ENV['MONGO_HOST']) && is_file(dirname(__DIR__, 2) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
}

$mongoUri = sprintf(
    'mongodb://%s:%s@%s:%s/?authSource=%s',
    $_ENV['MONGO_ROOT_USERNAME'] ?? 'mongo',
    $_ENV['MONGO_ROOT_PASSWORD'] ?? 'mongo_pass',
    $_ENV['MONGO_HOST'] ?? 'mongo',
    $_ENV['MONGO_PORT'] ?? '27017',
    $_ENV['MONGO_AUTH_SOURCE'] ?? 'admin'
);
$database = $_ENV['MONGO_BASENAME'] ?? 'app_db';

$manager = new Manager($mongoUri);

function ensureUsernamesIndexes(Manager $manager, string $database, string $collection = 'usernames'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                [
                    'key' => ['username' => 1],
                    'name' => 'uniq_username_ci',
                    'unique' => true,
                    'collation' => ['locale' => 'en', 'strength' => 2],
                ],
                ['key' => ['account_id' => 1], 'name' => 'idx_account'],
                ['key' => ['account_id' => 1, 'is_current' => 1], 'name' => 'idx_account_current'],
            ],
        ])
    );
}

function ensureContactsIndexes(Manager $manager, string $database, string $collection = 'contacts'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['account_id' => 1], 'name' => 'uniq_account', 'unique' => true],
            ],
        ])
    );
}

function ensureDocumentsIndexes(Manager $manager, string $database, string $collection = 'documents'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['hash' => 1], 'name' => 'uniq_hash', 'unique' => true],
                ['key' => ['source' => 1], 'name' => 'idx_source'],
            ],
        ])
    );
}

function ensureApiKeysIndexes(Manager $manager, string $database, string $collection = 'api_keys'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['key' => 1], 'name' => 'uniq_key', 'unique' => true],
                ['key' => ['account_id' => 1], 'name' => 'account_idx'],
            ],
        ])
    );
}

function ensureSessionsIndexes(Manager $manager, string $database, string $collection = 'sessions'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                [
                    'key' => ['expiresAt' => 1],
                    'name' => 'expires_ttl',
                    'expireAfterSeconds' => 0,
                ],
            ],
        ])
    );
}

function ensureCacheEntriesIndexes(Manager $manager, string $database, string $collection = 'cache_entries'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['key' => 1], 'name' => 'uniq_key', 'unique' => true],
                ['key' => ['updated_at' => -1], 'name' => 'idx_updated_at'],
                [
                    'key' => ['expires_at' => 1],
                    'name' => 'expires_ttl',
                    'expireAfterSeconds' => 0,
                ],
            ],
        ])
    );
}

function ensureStellarTomlsIndexes(Manager $manager, string $database, string $collection = 'stellar_tomls'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['home_domain' => 1], 'name' => 'uniq_home_domain', 'unique' => true],
                ['key' => ['status' => 1], 'name' => 'idx_status'],
                ['key' => ['ignored' => 1], 'name' => 'idx_ignored'],
                ['key' => ['last_attempt_at' => -1], 'name' => 'idx_last_attempt_at'],
                ['key' => ['last_success_at' => -1], 'name' => 'idx_last_success_at'],
                ['key' => ['observed_accounts.account_id' => 1], 'name' => 'idx_observed_account'],
                ['key' => ['declared_accounts' => 1], 'name' => 'idx_declared_accounts'],
                ['key' => ['currencies.key' => 1], 'name' => 'idx_currency_key'],
            ],
        ])
    );
}

function ensureStellarTomlRunsIndexes(Manager $manager, string $database, string $collection = 'stellar_toml_runs'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['created_at' => -1], 'name' => 'idx_created_at'],
            ],
        ])
    );
}

try {
    ensureUsernamesIndexes($manager, $database);
    ensureContactsIndexes($manager, $database);
    ensureDocumentsIndexes($manager, $database);
    ensureApiKeysIndexes($manager, $database);
    ensureSessionsIndexes($manager, $database);
    ensureCacheEntriesIndexes($manager, $database);
    ensureStellarTomlsIndexes($manager, $database);
    ensureStellarTomlRunsIndexes($manager, $database);
    echo "Mongo indexes ensured\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[mongo-indexes] " . $e->getMessage() . PHP_EOL);
    exit(1);
}
