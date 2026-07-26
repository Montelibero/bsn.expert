<?php

declare(strict_types=1);

$base_url = rtrim((string) (getenv('ROUTE_SMOKE_BASE_URL') ?: ($argv[1] ?? '')), '/');
$secret = (string) (getenv('TELEGRAM_WEBHOOK_SMOKE_SECRET') ?: '');
if ($base_url === '' || $secret === '') {
    fwrite(STDERR, "Set ROUTE_SMOKE_BASE_URL and TELEGRAM_WEBHOOK_SMOKE_SECRET.\n");
    exit(2);
}

$update_id = (string) random_int(1000000000000000, 9000000000000000);
$payload = json_encode([
    'update_id' => $update_id,
    'message' => [
        'message_id' => 7001,
        'date' => time(),
        'from' => [
            'id' => 42,
            'is_bot' => false,
            'first_name' => 'CI',
            'username' => 'ci_user',
        ],
        'chat' => [
            'id' => 42,
            'type' => 'private',
            'username' => 'ci_user',
        ],
        'text' => 'GBAFR44OY2EXAVZ6TL3ENEWEATKLHCKVGKQAZLZFWKUDYXVCLKOMZEIU',
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

/** @return array<string, mixed> */
function postTelegramWebhook(string $url, string $secret, string $payload): array
{
    $Curl = curl_init($url);
    if ($Curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt_array($Curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Telegram-Bot-Api-Secret-Token: ' . $secret,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($Curl);
    $status = curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($Curl);
    curl_close($Curl);

    if (!is_string($body)) {
        throw new RuntimeException('Webhook request failed: ' . $error);
    }
    if ($status !== 200) {
        throw new RuntimeException(sprintf('Webhook returned HTTP %d: %s', $status, $body));
    }

    $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Webhook response must be a JSON object.');
    }

    return $decoded;
}

try {
    $first = postTelegramWebhook($base_url . '/telegram/webhook', $secret, $payload);
    if (($first['method'] ?? null) !== 'setMessageReaction'
        || ($first['reaction'][0]['emoji'] ?? null) !== '👀'
    ) {
        throw new RuntimeException('The first webhook delivery was not durably accepted with the eyes reaction.');
    }

    $duplicate = postTelegramWebhook($base_url . '/telegram/webhook', $secret, $payload);
    if (($duplicate['ok'] ?? false) !== true || ($duplicate['status'] ?? null) !== 'duplicate') {
        throw new RuntimeException('The repeated update was not deduplicated by the durable inbox.');
    }
} catch (Throwable $Exception) {
    fwrite(STDERR, $Exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Telegram webhook integration smoke passed.\n");
