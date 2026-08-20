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

$mongoUsername = $_ENV['MONGO_ROOT_USERNAME'] ?? 'mongo';
$mongoPassword = $_ENV['MONGO_ROOT_PASSWORD'] ?? 'mongo_pass';
$mongoHost = $_ENV['MONGO_HOST'] ?? 'mongo';
$mongoPort = $_ENV['MONGO_PORT'] ?? '27017';
$mongoAuthSource = $_ENV['MONGO_AUTH_SOURCE'] ?? 'admin';
$mongoUri = $mongoUsername === ''
    ? sprintf('mongodb://%s:%s/', $mongoHost, $mongoPort)
    : sprintf(
        'mongodb://%s:%s@%s:%s/?authSource=%s',
        $mongoUsername,
        $mongoPassword,
        $mongoHost,
        $mongoPort,
        $mongoAuthSource
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

function ensureTransactionConsolidationIndexes(
    Manager $manager,
    string $database,
    string $collection = 'transaction_consolidation_drafts',
): void {
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['owner_account_id' => 1], 'name' => 'uniq_owner_account', 'unique' => true],
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
                [
                    'key' => ['key_digest' => 1],
                    'name' => 'uniq_key_digest',
                    'unique' => true,
                    'sparse' => true,
                ],
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

function ensureGristSnapshotsIndexes(Manager $manager, string $database, string $collection = 'grist_snapshots'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['updated_at' => -1], 'name' => 'idx_updated_at'],
            ],
        ])
    );
}

function ensureGristSyncJobsIndexes(Manager $manager, string $database, string $collection = 'grist_sync_jobs'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['due_at' => 1], 'name' => 'idx_due_at'],
                ['key' => ['lease_until' => 1], 'name' => 'idx_lease_until'],
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

function ensureStellarTomlImagesIndexes(Manager $manager, string $database, string $collection = 'stellar_toml_images'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['image_id' => 1], 'name' => 'uniq_image_id', 'unique' => true],
                ['key' => ['source_url' => 1], 'name' => 'idx_source_url'],
                ['key' => ['status' => 1], 'name' => 'idx_status'],
                ['key' => ['next_check_at' => 1], 'name' => 'idx_next_check_at'],
                ['key' => ['last_success_at' => -1], 'name' => 'idx_last_success_at'],
            ],
        ])
    );
}

function ensureStellarTomlImageRefsIndexes(Manager $manager, string $database, string $collection = 'stellar_toml_image_refs'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                ['key' => ['home_domain' => 1], 'name' => 'idx_home_domain'],
                ['key' => ['entity_type' => 1, 'entity_key' => 1, 'role' => 1], 'name' => 'idx_entity_role'],
                ['key' => ['image_id' => 1], 'name' => 'idx_image_id'],
                ['key' => ['status' => 1], 'name' => 'idx_status'],
            ],
        ])
    );
}

function ensureTelegramUpdatesIndexes(Manager $manager, string $database, string $collection = 'telegram_updates'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                [
                    'key' => ['state' => 1, 'available_at' => 1, 'received_at' => 1],
                    'name' => 'idx_worker_due',
                ],
                ['key' => ['state' => 1, 'lease_until' => 1], 'name' => 'idx_worker_lease'],
                [
                    'key' => ['purge_at' => 1],
                    'name' => 'purge_ttl',
                    'expireAfterSeconds' => 0,
                ],
            ],
        ])
    );
}

function ensureTelegramUsageIndexes(Manager $manager, string $database, string $collection = 'telegram_usage_events'): void
{
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                [
                    'key' => ['day_utc' => 1, 'action' => 1, 'occurred_at' => 1],
                    'name' => 'idx_daily_events',
                ],
                [
                    'key' => ['expires_at' => 1],
                    'name' => 'expires_ttl',
                    'expireAfterSeconds' => 0,
                ],
            ],
        ])
    );
}

function ensureTelegramDailySubscriptionsIndexes(
    Manager $manager,
    string $database,
    string $collection = 'telegram_daily_subscriptions',
): void {
    $manager->executeCommand(
        $database,
        new Command([
            'createIndexes' => $collection,
            'indexes' => [
                [
                    'key' => ['enabled' => 1, 'last_sent_day_utc' => 1],
                    'name' => 'idx_daily_due',
                ],
                ['key' => ['enabled' => 1, 'claim_until' => 1], 'name' => 'idx_daily_claim'],
                ['key' => ['enabled' => 1, 'retry_after' => 1], 'name' => 'idx_daily_retry'],
            ],
        ])
    );
}

try {
    ensureUsernamesIndexes($manager, $database);
    ensureContactsIndexes($manager, $database);
    ensureTransactionConsolidationIndexes($manager, $database);
    ensureDocumentsIndexes($manager, $database);
    ensureApiKeysIndexes($manager, $database);
    ensureSessionsIndexes($manager, $database);
    ensureCacheEntriesIndexes($manager, $database);
    ensureGristSnapshotsIndexes($manager, $database);
    ensureGristSyncJobsIndexes($manager, $database);
    ensureStellarTomlsIndexes($manager, $database);
    ensureStellarTomlRunsIndexes($manager, $database);
    ensureStellarTomlImagesIndexes($manager, $database);
    ensureStellarTomlImageRefsIndexes($manager, $database);
    ensureTelegramUpdatesIndexes($manager, $database);
    ensureTelegramUsageIndexes($manager, $database);
    ensureTelegramDailySubscriptionsIndexes($manager, $database);
    echo "Mongo indexes ensured\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[mongo-indexes] " . $e->getMessage() . PHP_EOL);
    exit(1);
}
