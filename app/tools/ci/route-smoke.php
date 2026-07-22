<?php

declare(strict_types=1);

$base_url = rtrim((string) (getenv('ROUTE_SMOKE_BASE_URL') ?: ($argv[1] ?? '')), '/');
if ($base_url === '') {
    fwrite(STDERR, "Set ROUTE_SMOKE_BASE_URL or pass the base URL as the first argument.\n");
    exit(2);
}

$checks = [
    ['path' => '/health', 'status' => 200, 'json_status' => 'ok'],
    ['path' => '/', 'status' => 200],
    ['path' => '/tools/', 'status' => 200],
    ['path' => '/accounts/', 'status' => 200],
    ['path' => '/documents/', 'status' => 200],
    ['path' => '/tokens/?format=json', 'status' => 200, 'json' => true],
    ['path' => '/graph', 'status' => 200],
    ['path' => '/who_are_you', 'status' => 200],
    ['path' => '/token/XLM?ci=1', 'status' => 301, 'location' => '/tokens/XLM?ci=1'],
    ['path' => '/contracts/demo?ci=1', 'status' => 301, 'location' => '/documents/demo?ci=1'],
    [
        'path' => '/api/contacts/sync',
        'method' => 'OPTIONS',
        'status' => 204,
        'request_headers' => [
            'Origin: https://client.example',
            'Access-Control-Request-Method: POST',
            'Access-Control-Request-Headers: authorization,content-type',
        ],
        'response_headers' => [
            'access-control-allow-origin' => '*',
            'access-control-allow-methods' => 'POST, OPTIONS',
            'access-control-allow-headers' => 'Authorization, Content-Type',
            'access-control-max-age' => '86400',
        ],
        'body' => '',
    ],
    ['path' => '/route-that-must-not-exist-ci', 'status' => 404],
    ['path' => '/composer.lock', 'status' => 404],
    ['path' => '/composer.json', 'status' => 404],
    ['path' => '/Caddyfile', 'status' => 404],
    ['path' => '/cron/root', 'status' => 404],
    ['path' => '/twig/page.twig', 'status' => 404],
    ['path' => '/tests/MarkdownRendererTest.php', 'status' => 404],
    ['path' => '/worker.php', 'status' => 404],
];

$errors = [];
foreach ($checks as $check) {
    $headers = [];
    $header_counts = [];
    $Curl = curl_init($base_url . $check['path']);
    if ($Curl === false) {
        $errors[] = $check['path'] . ': unable to initialize cURL';
        continue;
    }

    curl_setopt_array($Curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $check['method'] ?? 'GET',
        CURLOPT_HTTPHEADER => array_merge(
            ['Accept: text/html, application/json;q=0.9'],
            $check['request_headers'] ?? []
        ),
        CURLOPT_HEADERFUNCTION => static function ($Curl, string $line) use (&$headers, &$header_counts): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $headers[$name] = trim($parts[1]);
                $header_counts[$name] = ($header_counts[$name] ?? 0) + 1;
            }

            return $length;
        },
    ]);

    $body = curl_exec($Curl);
    $status = curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($Curl);

    if ($body === false) {
        $errors[] = sprintf('%s: request failed: %s', $check['path'], $error);
        continue;
    }
    if ($status !== $check['status']) {
        $errors[] = sprintf('%s: expected HTTP %d, got %d', $check['path'], $check['status'], $status);
        continue;
    }

    foreach ($check['response_headers'] ?? [] as $name => $expected_value) {
        $actual_value = $headers[$name] ?? null;
        if ($actual_value !== $expected_value) {
            $errors[] = sprintf(
                '%s: expected header %s: %s, got %s',
                $check['path'],
                $name,
                $expected_value,
                $actual_value ?? '(missing)'
            );
        }
        if (($header_counts[$name] ?? 0) !== 1) {
            $errors[] = sprintf(
                '%s: expected header %s exactly once, got %d occurrences',
                $check['path'],
                $name,
                $header_counts[$name] ?? 0
            );
        }
    }

    if (array_key_exists('body', $check) && $body !== $check['body']) {
        $errors[] = sprintf('%s: expected an empty response body', $check['path']);
    }

    if (isset($check['location'])) {
        $location = $headers['location'] ?? '';
        $actual_path = parse_url($location, PHP_URL_PATH) ?: '';
        $actual_query = parse_url($location, PHP_URL_QUERY);
        if ($actual_query !== null) {
            $actual_path .= '?' . $actual_query;
        }
        if ($actual_path !== $check['location']) {
            $errors[] = sprintf(
                '%s: expected Location %s, got %s',
                $check['path'],
                $check['location'],
                $location ?: '(missing)'
            );
        }
    }

    if (($check['json'] ?? false) || isset($check['json_status'])) {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $Exception) {
            $errors[] = sprintf('%s: invalid JSON: %s', $check['path'], $Exception->getMessage());
            continue;
        }
        if (isset($check['json_status']) && ($decoded['status'] ?? null) !== $check['json_status']) {
            $errors[] = sprintf('%s: unexpected JSON status', $check['path']);
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Route smoke passed for %d HTTP contracts.\n", count($checks)));
