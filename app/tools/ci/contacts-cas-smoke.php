<?php

declare(strict_types=1);

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Manager;
use Montelibero\BSN\ContactsManager;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mongo_host = (string) getenv('MONGO_HOST');
$mongo_port = (string) getenv('MONGO_PORT');
$mongo_database = (string) getenv('MONGO_BASENAME');
$mongo_username = (string) getenv('MONGO_ROOT_USERNAME');
$mongo_password = (string) getenv('MONGO_ROOT_PASSWORD');
$mongo_auth_source = (string) (getenv('MONGO_AUTH_SOURCE') ?: 'admin');

if ($mongo_host === '' || $mongo_port === '' || $mongo_database === '') {
    fwrite(STDERR, "MongoDB connection settings are missing.\n");
    exit(2);
}

$Mongo = new Manager(sprintf(
    'mongodb://%s:%s@%s:%s/?authSource=%s',
    rawurlencode($mongo_username),
    rawurlencode($mongo_password),
    $mongo_host,
    $mongo_port,
    rawurlencode($mongo_auth_source)
));
$Contacts = new ContactsManager($Mongo, $mongo_database);

$account_id = 'contacts-cas-smoke-' . bin2hex(random_bytes(8));
$contact_id = 'GDGXXBP7P4YXZFTZX5RGCVUYK5YB24ANMD5IPUT3MFBQGSMDCK75W67S';

try {
    $was_created = $Contacts->bulkUpdate($account_id, [
        $contact_id => [
            'name' => 'Initial CAS fixture',
            'updated_at' => new UTCDateTime(1000),
        ],
    ], 0);
    if (!$was_created) {
        throw new RuntimeException('Initial CAS fixture was not created.');
    }

    $stale_snapshot = $Contacts->getSyncSnapshot($account_id);
    $was_updated = $Contacts->bulkUpdate($account_id, [
        $contact_id => [
            'name' => 'Newer CAS fixture',
            'updated_at' => new UTCDateTime(3000),
        ],
    ], $stale_snapshot['revision']);
    if (!$was_updated) {
        throw new RuntimeException('Current CAS update was rejected.');
    }

    $stale_write_succeeded = $Contacts->bulkUpdate($account_id, [
        $contact_id => [
            'name' => 'Stale CAS fixture',
            'updated_at' => new UTCDateTime(2000),
        ],
    ], $stale_snapshot['revision']);
    if ($stale_write_succeeded) {
        throw new RuntimeException('Stale CAS update unexpectedly succeeded.');
    }

    $final_snapshot = $Contacts->getSyncSnapshot($account_id);
    $final_contact = $final_snapshot['items'][$contact_id] ?? null;
    if ($final_snapshot['revision'] !== 2
        || !is_array($final_contact)
        || $final_contact['label'] !== 'Newer CAS fixture'
        || $final_contact['updated_at'] !== 3000
    ) {
        throw new RuntimeException('Stale CAS update overwrote the newer contact.');
    }
} catch (Throwable $Throwable) {
    fwrite(STDERR, $Throwable->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Contacts CAS regression smoke passed.\n");
