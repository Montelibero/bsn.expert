#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use Montelibero\BSN\ApiTokenDigest;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only run in CLI mode.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$cli_arguments = $_SERVER['argv'] ?? [];
if (!is_array($cli_arguments)) {
    $cli_arguments = [];
}
$arguments = [];
foreach (array_slice($cli_arguments, 1) as $argument) {
    if (is_string($argument)) {
        $arguments[] = $argument;
    }
}
if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, "Usage: php cli/migrate-api-key-digests.php [--dry-run|--apply]\n");
    exit(0);
}

$known_arguments = ['--dry-run', '--apply'];
$unknown_arguments = array_values(array_diff($arguments, $known_arguments));
if ($unknown_arguments !== [] || (in_array('--dry-run', $arguments, true) && in_array('--apply', $arguments, true))) {
    fwrite(STDERR, "Use exactly one of --dry-run or --apply. The default is --dry-run.\n");
    exit(2);
}

$apply = in_array('--apply', $arguments, true);

if (empty($_ENV['MONGO_HOST']) && is_file(dirname(__DIR__, 2) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
}

$mongo_username = $_ENV['MONGO_ROOT_USERNAME'] ?? 'mongo';
$mongo_password = $_ENV['MONGO_ROOT_PASSWORD'] ?? 'mongo_pass';
$mongo_host = $_ENV['MONGO_HOST'] ?? 'mongo';
$mongo_port = $_ENV['MONGO_PORT'] ?? '27017';
$mongo_auth_source = $_ENV['MONGO_AUTH_SOURCE'] ?? 'admin';
$mongo_uri = $mongo_username === ''
    ? sprintf('mongodb://%s:%s/', $mongo_host, $mongo_port)
    : sprintf(
        'mongodb://%s:%s@%s:%s/?authSource=%s',
        $mongo_username,
        $mongo_password,
        $mongo_host,
        $mongo_port,
        $mongo_auth_source
    );
$database = $_ENV['MONGO_BASENAME'] ?? 'app_db';
$namespace = $database . '.api_keys';
$manager = new Manager($mongo_uri);

$cursor = $manager->executeQuery(
    $namespace,
    new Query([], [
        'projection' => [
            '_id' => 1,
            'key' => 1,
            'key_digest' => 1,
            'key_digest_algorithm' => 1,
        ],
    ])
);

$scanned = 0;
$already_valid = 0;
$digest_only = 0;
$algorithm_metadata_updates = 0;
$errors = [];
$updates = [];

foreach ($cursor as $document) {
    $scanned++;
    $id = (string) $document->_id;
    $raw_key = $document->key ?? null;
    $stored_digest = $document->key_digest ?? null;
    $stored_algorithm = $document->key_digest_algorithm ?? null;

    if (!is_string($raw_key) || $raw_key === '') {
        if (ApiTokenDigest::isValid($stored_digest) && $stored_algorithm === ApiTokenDigest::ALGORITHM) {
            $digest_only++;
            continue;
        }

        $errors[] = sprintf('API key document %s has neither a usable raw key nor a supported digest.', $id);
        continue;
    }

    $expected_digest = ApiTokenDigest::fromToken($raw_key);
    if ($stored_digest !== null && !ApiTokenDigest::isValid($stored_digest)) {
        $errors[] = sprintf('API key document %s has a malformed digest.', $id);
        continue;
    }
    if (is_string($stored_digest) && !hash_equals(strtolower($stored_digest), $expected_digest)) {
        $errors[] = sprintf('API key document %s has a digest that does not match its raw key.', $id);
        continue;
    }
    if ($stored_algorithm !== null && $stored_algorithm !== ApiTokenDigest::ALGORITHM) {
        $errors[] = sprintf('API key document %s uses an unsupported digest algorithm.', $id);
        continue;
    }

    $set = [];
    if ($stored_digest === null) {
        $set['key_digest'] = $expected_digest;
    }
    if ($stored_algorithm === null) {
        $set['key_digest_algorithm'] = ApiTokenDigest::ALGORITHM;
        $algorithm_metadata_updates++;
    }

    if ($set === []) {
        $already_valid++;
        continue;
    }

    $updates[] = [
        'id' => $document->_id,
        'set' => $set,
    ];
}

fwrite(STDOUT, sprintf("Mode: %s\n", $apply ? 'apply' : 'dry-run'));
fwrite(STDOUT, sprintf("Documents scanned: %d\n", $scanned));
fwrite(STDOUT, sprintf("Documents ready for update: %d\n", count($updates)));
fwrite(STDOUT, sprintf("Already valid: %d\n", $already_valid));
fwrite(STDOUT, sprintf("Digest-only documents: %d\n", $digest_only));
fwrite(STDOUT, sprintf("Algorithm metadata updates: %d\n", $algorithm_metadata_updates));
fwrite(STDOUT, sprintf("Errors: %d\n", count($errors)));

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    fwrite(STDERR, "No changes were applied.\n");
    exit(1);
}

if (!$apply) {
    fwrite(STDOUT, "Dry run complete; no changes were applied.\n");
    exit(0);
}

$updated = 0;
foreach (array_chunk($updates, 500) as $chunk) {
    $bulk = new BulkWrite();
    foreach ($chunk as $update) {
        $bulk->update(
            ['_id' => $update['id']],
            ['$set' => $update['set']],
            ['limit' => 1]
        );
    }

    $result = $manager->executeBulkWrite($namespace, $bulk);
    if ($result->getMatchedCount() !== count($chunk)) {
        throw new RuntimeException('An API key changed while digests were being applied; rerun the dry run.');
    }
    $updated += $result->getModifiedCount();
}

fwrite(STDOUT, sprintf("Documents updated: %d\n", $updated));
fwrite(STDOUT, "Digest migration complete. Raw keys were intentionally retained for rollback compatibility.\n");
