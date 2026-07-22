<?php

declare(strict_types=1);

const WRITER_TOKEN = '111111111111111111111111111111111111111111111111';
const READER_TOKEN = '222222222222222222222222222222222222222222222222';
const UPDATER_TOKEN = '333333333333333333333333333333333333333333333333';
const DELETER_TOKEN = '444444444444444444444444444444444444444444444444';
const CONTACT_ACCOUNT = 'GDGXXBP7P4YXZFTZX5RGCVUYK5YB24ANMD5IPUT3MFBQGSMDCK75W67S';
const CONTACT_LABEL = 'First sync regression fixture';
const UPDATED_CONTACT_LABEL = 'Updated sync regression fixture';

$base_url = rtrim((string) (getenv('ROUTE_SMOKE_BASE_URL') ?: ($argv[1] ?? '')), '/');
if ($base_url === '') {
    fwrite(STDERR, "Set ROUTE_SMOKE_BASE_URL or pass the base URL as the first argument.\n");
    exit(2);
}

/**
 * @param array<string, array{label: ?string, updated_at: int}> $items
 * @param array<string, mixed> $request_extra
 * @return array<string, mixed>
 */
function syncContacts(
    string $base_url,
    string $token,
    array $items,
    array $request_extra = [],
    int $expected_status = 200,
): array
{
    $request_data = [
        'current_timestamp' => (int) (microtime(true) * 1000),
        'items' => $items,
    ] + $request_extra;
    $payload = json_encode($request_data, JSON_THROW_ON_ERROR);

    $Curl = curl_init($base_url . '/api/contacts/sync');
    if ($Curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt_array($Curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($Curl);
    $status = curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($Curl);

    if ($body === false) {
        throw new RuntimeException('Contacts sync request failed: ' . $error);
    }
    if ($status !== $expected_status) {
        throw new RuntimeException(sprintf(
            'Contacts sync returned HTTP %d instead of %d: %s',
            $status,
            $expected_status,
            $body
        ));
    }

    $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($response)) {
        throw new RuntimeException('Contacts sync returned an unexpected response.');
    }
    if ($expected_status === 200 && ($response['status'] ?? null) !== 'OK') {
        throw new RuntimeException('Contacts sync returned an unexpected success response.');
    }
    if ($expected_status !== 200 && ($response['status'] ?? null) !== 'error') {
        throw new RuntimeException('Contacts sync returned an unexpected error response.');
    }

    return $response;
}

try {
    $invalid_cursor_response = syncContacts(
        $base_url,
        READER_TOKEN,
        [],
        ['cursor' => -1],
        400
    );
    if (($invalid_cursor_response['message'] ?? null) !== 'Wrong `cursor` value') {
        throw new RuntimeException('Invalid contact cursor did not return the expected error.');
    }

    $future_cursor_response = syncContacts(
        $base_url,
        READER_TOKEN,
        [],
        ['cursor' => PHP_INT_MAX],
        409
    );
    if (($future_cursor_response['message'] ?? null)
        !== '`cursor` is ahead of the current server revision'
    ) {
        throw new RuntimeException('Future contact cursor did not return the expected conflict.');
    }

    $updated_at = (int) (microtime(true) * 1000) - 60000;
    $write_response = syncContacts($base_url, WRITER_TOKEN, [
        CONTACT_ACCOUNT => [
            'label' => CONTACT_LABEL,
            'updated_at' => $updated_at,
        ],
    ]);

    if (($write_response['report']['added'] ?? null) !== [CONTACT_ACCOUNT]) {
        throw new RuntimeException('First sync did not report the contact as added.');
    }

    $read_response = syncContacts($base_url, READER_TOKEN, [], ['cursor' => null]);
    $stored_contact = $read_response['items'][CONTACT_ACCOUNT] ?? null;
    if (!is_array($stored_contact) || ($stored_contact['label'] ?? null) !== CONTACT_LABEL) {
        throw new RuntimeException('A second API key could not read the first synced contact.');
    }
    $reader_cursor = $read_response['next_cursor'] ?? null;
    if (!is_int($reader_cursor)) {
        throw new RuntimeException('Initial contact sync did not return a cursor.');
    }

    $renamed_at = $updated_at + 1;
    $update_response = syncContacts($base_url, UPDATER_TOKEN, [
        CONTACT_ACCOUNT => [
            'label' => UPDATED_CONTACT_LABEL,
            'updated_at' => $renamed_at,
        ],
    ]);

    if (($update_response['report']['updated'] ?? null) !== [CONTACT_ACCOUNT]) {
        throw new RuntimeException('Contact rename was not reported as updated.');
    }
    $updated_contact = $update_response['items'][CONTACT_ACCOUNT] ?? null;
    if (!is_array($updated_contact) || ($updated_contact['label'] ?? null) !== UPDATED_CONTACT_LABEL) {
        throw new RuntimeException('Contact rename response did not contain the post-write value.');
    }

    $lost_update_response = syncContacts(
        $base_url,
        READER_TOKEN,
        [],
        ['cursor' => $reader_cursor]
    );
    $lost_updated_contact = $lost_update_response['items'][CONTACT_ACCOUNT] ?? null;
    if (!is_array($lost_updated_contact)
        || ($lost_updated_contact['label'] ?? null) !== UPDATED_CONTACT_LABEL
    ) {
        throw new RuntimeException('An established API key missed an offline contact update.');
    }

    $offline_update_response = syncContacts(
        $base_url,
        READER_TOKEN,
        [],
        ['cursor' => $reader_cursor]
    );
    $offline_updated_contact = $offline_update_response['items'][CONTACT_ACCOUNT] ?? null;
    if (!is_array($offline_updated_contact)
        || ($offline_updated_contact['label'] ?? null) !== UPDATED_CONTACT_LABEL
    ) {
        throw new RuntimeException('A retry after a lost response did not replay the contact update.');
    }
    $retried_cursor = $offline_update_response['next_cursor'] ?? null;
    if (!is_int($retried_cursor) || $retried_cursor <= $reader_cursor) {
        throw new RuntimeException('Contact update retry did not return the next cursor.');
    }

    $acknowledged_update_response = syncContacts(
        $base_url,
        READER_TOKEN,
        [],
        ['cursor' => $retried_cursor]
    );
    if (($acknowledged_update_response['items'] ?? null) !== []) {
        throw new RuntimeException('An acknowledged contact update was delivered again.');
    }

    $deleted_at = $renamed_at + 1;
    $delete_response = syncContacts($base_url, DELETER_TOKEN, [
        CONTACT_ACCOUNT => [
            'label' => null,
            'updated_at' => $deleted_at,
        ],
    ]);

    if (($delete_response['report']['deleted'] ?? null) !== [CONTACT_ACCOUNT]) {
        throw new RuntimeException('Contact deletion was not reported as deleted.');
    }
    $deleted_contact = $delete_response['items'][CONTACT_ACCOUNT] ?? null;
    if (!is_array($deleted_contact)
        || !array_key_exists('label', $deleted_contact)
        || $deleted_contact['label'] !== null
    ) {
        throw new RuntimeException('Contact deletion response did not contain the post-write tombstone.');
    }

    $offline_delete_response = syncContacts(
        $base_url,
        READER_TOKEN,
        [],
        ['cursor' => $retried_cursor]
    );
    $offline_deleted_contact = $offline_delete_response['items'][CONTACT_ACCOUNT] ?? null;
    if (!is_array($offline_deleted_contact)
        || !array_key_exists('label', $offline_deleted_contact)
        || $offline_deleted_contact['label'] !== null
    ) {
        throw new RuntimeException('An established API key missed an offline contact deletion.');
    }
    $deleted_cursor = $offline_delete_response['next_cursor'] ?? null;
    if (!is_int($deleted_cursor) || $deleted_cursor <= $retried_cursor) {
        throw new RuntimeException('Contact deletion did not advance the cursor.');
    }

    $acknowledged_delete_response = syncContacts(
        $base_url,
        READER_TOKEN,
        [],
        ['cursor' => $deleted_cursor]
    );
    if (($acknowledged_delete_response['items'] ?? null) !== []) {
        throw new RuntimeException('An acknowledged contact deletion was delivered again.');
    }
} catch (Throwable $Throwable) {
    fwrite(STDERR, $Throwable->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Contacts sync regression smoke passed.\n");
