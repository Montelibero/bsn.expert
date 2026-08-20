#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
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
    fwrite(STDOUT, "Usage: php cli/purge-api-key-raw-secrets.php [--dry-run|--apply]\n");
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
$collection = 'api_keys';
$namespace = $database . '.' . $collection;
$manager = new Manager($mongo_uri);

$indexes = $manager->executeCommand(
    $database,
    new Command(['listIndexes' => $collection])
)->toArray();
$digest_index_valid = false;
$raw_index_present = false;
foreach ($indexes as $index) {
    $name = $index->name ?? null;
    if ($name === 'uniq_key') {
        $raw_index_present = true;
    }
    if (
        $name === 'uniq_key_digest'
        && ($index->unique ?? false) === true
        && (int) ($index->key->key_digest ?? 0) === 1
    ) {
        $digest_index_valid = true;
    }
}

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
$already_digest_only = 0;
$raw_documents = [];
$errors = [];

if (!$digest_index_valid) {
    $errors[] = 'Required unique index uniq_key_digest is missing or invalid.';
}

foreach ($cursor as $document) {
    $scanned++;
    $id = (string) $document->_id;
    $stored_digest = $document->key_digest ?? null;
    $stored_algorithm = $document->key_digest_algorithm ?? null;

    if (!ApiTokenDigest::isValid($stored_digest)) {
        $errors[] = sprintf('API key document %s has a missing or malformed digest.', $id);
        continue;
    }
    if ($stored_algorithm !== ApiTokenDigest::ALGORITHM) {
        $errors[] = sprintf('API key document %s uses an unsupported digest algorithm.', $id);
        continue;
    }

    if (!property_exists($document, 'key')) {
        $already_digest_only++;
        continue;
    }

    $raw_key = $document->key;
    if (!is_string($raw_key) || $raw_key === '') {
        $errors[] = sprintf('API key document %s has an invalid raw key field.', $id);
        continue;
    }
    if (!hash_equals($stored_digest, ApiTokenDigest::fromToken($raw_key))) {
        $errors[] = sprintf('API key document %s has a digest that does not match its raw key.', $id);
        continue;
    }

    $raw_documents[] = [
        'id' => $document->_id,
        'digest' => $stored_digest,
    ];
}

fwrite(STDOUT, sprintf("Mode: %s\n", $apply ? 'apply' : 'dry-run'));
fwrite(STDOUT, sprintf("Documents scanned: %d\n", $scanned));
fwrite(STDOUT, sprintf("Raw secrets ready for removal: %d\n", count($raw_documents)));
fwrite(STDOUT, sprintf("Already digest-only: %d\n", $already_digest_only));
fwrite(STDOUT, sprintf("Legacy uniq_key index present: %s\n", $raw_index_present ? 'yes' : 'no'));
fwrite(STDOUT, sprintf("Valid uniq_key_digest index present: %s\n", $digest_index_valid ? 'yes' : 'no'));
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

if ($raw_index_present) {
    $manager->executeCommand(
        $database,
        new Command([
            'dropIndexes' => $collection,
            'index' => 'uniq_key',
        ])
    );
    fwrite(STDOUT, "Legacy uniq_key index dropped.\n");
}

$removed = 0;
foreach (array_chunk($raw_documents, 500) as $chunk) {
    $bulk = new BulkWrite();
    foreach ($chunk as $document) {
        $bulk->update(
            [
                '_id' => $document['id'],
                'key_digest' => $document['digest'],
                'key_digest_algorithm' => ApiTokenDigest::ALGORITHM,
            ],
            ['$unset' => ['key' => '']],
            ['limit' => 1]
        );
    }

    $result = $manager->executeBulkWrite($namespace, $bulk);
    if ($result->getMatchedCount() !== count($chunk)) {
        throw new RuntimeException('An API key changed while raw secrets were being removed; rerun the dry run.');
    }
    $removed += $result->getModifiedCount();
}

$remaining_raw = $manager->executeQuery(
    $namespace,
    new Query(['key' => ['$exists' => true]], ['projection' => ['_id' => 1]])
)->toArray();
if ($remaining_raw !== []) {
    throw new RuntimeException(sprintf(
        '%d API key document(s) still contain a raw key; rerun the dry run.',
        count($remaining_raw)
    ));
}

fwrite(STDOUT, sprintf("Raw secrets removed: %d\n", $removed));
fwrite(STDOUT, "Raw API key purge complete. Existing client tokens are unchanged.\n");
