<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;

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

try {
    ensureUsernamesIndexes($manager, $database);
    ensureContactsIndexes($manager, $database);
    echo "Mongo indexes ensured\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[mongo-indexes] " . $e->getMessage() . PHP_EOL);
    exit(1);
}
