<?php

declare(strict_types=1);

const WRITER_TOKEN = '111111111111111111111111111111111111111111111111';
const READER_TOKEN = '222222222222222222222222222222222222222222222222';
const CONTACT_ACCOUNT = 'GDGXXBP7P4YXZFTZX5RGCVUYK5YB24ANMD5IPUT3MFBQGSMDCK75W67S';
const CONTACT_LABEL = 'First sync regression fixture';

$base_url = rtrim((string) (getenv('ROUTE_SMOKE_BASE_URL') ?: ($argv[1] ?? '')), '/');
if ($base_url === '') {
    fwrite(STDERR, "Set ROUTE_SMOKE_BASE_URL or pass the base URL as the first argument.\n");
    exit(2);
}

/**
 * @param array<string, array{label: ?string, updated_at: int}> $items
 * @return array<string, mixed>
 */
function syncContacts(string $base_url, string $token, array $items): array
{
    $payload = json_encode([
        'current_timestamp' => (int) (microtime(true) * 1000),
        'items' => $items,
    ], JSON_THROW_ON_ERROR);

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
    if ($status !== 200) {
        throw new RuntimeException(sprintf('Contacts sync returned HTTP %d: %s', $status, $body));
    }

    $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($response) || ($response['status'] ?? null) !== 'OK') {
        throw new RuntimeException('Contacts sync returned an unexpected response.');
    }

    return $response;
}

try {
    $updated_at = (int) (microtime(true) * 1000);
    $write_response = syncContacts($base_url, WRITER_TOKEN, [
        CONTACT_ACCOUNT => [
            'label' => CONTACT_LABEL,
            'updated_at' => $updated_at,
        ],
    ]);

    if (($write_response['report']['added'] ?? null) !== [CONTACT_ACCOUNT]) {
        throw new RuntimeException('First sync did not report the contact as added.');
    }

    $read_response = syncContacts($base_url, READER_TOKEN, []);
    $stored_contact = $read_response['items'][CONTACT_ACCOUNT] ?? null;
    if (!is_array($stored_contact) || ($stored_contact['label'] ?? null) !== CONTACT_LABEL) {
        throw new RuntimeException('A second API key could not read the first synced contact.');
    }
} catch (Throwable $Throwable) {
    fwrite(STDERR, $Throwable->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Contacts first-sync regression smoke passed.\n");
