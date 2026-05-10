<?php

use Montelibero\BSN\ApplicationContext;

/** @var ApplicationContext $App */
$App = require __DIR__ . '/bootstrap.php';

$handler = static function () use ($App): void {
    try {
        normalizeFrankenPhpWorkerRequest();
        $App->handleRequest();
    } catch (Throwable $Throwable) {
        error_log(sprintf(
            'PHP request failed: %s %s',
            $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            $_SERVER['REQUEST_URI'] ?? ''
        ));
        error_log((string) $Throwable);

        if (!headers_sent()) {
            http_response_code(500);
        }

        echo 'Internal Server Error';
    }
};

function normalizeFrankenPhpWorkerRequest(): void
{
    $original_uri = $_SERVER['HTTP_X_ORIGINAL_URI'] ?? null;
    if (is_string($original_uri) && $original_uri !== '') {
        $_SERVER['REQUEST_URI'] = $original_uri;
    }

    $_SERVER['SCRIPT_NAME'] = '/main.php';
    $_SERVER['PHP_SELF'] = '/main.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/main.php';
}

$max_requests = (int) ($_SERVER['MAX_REQUESTS'] ?? 200);

for ($request_number = 0; $max_requests === 0 || $request_number < $max_requests; $request_number++) {
    $keep_running = frankenphp_handle_request($handler);

    gc_collect_cycles();

    if (!$keep_running) {
        break;
    }
}
