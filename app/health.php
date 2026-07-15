<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;

require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$mongo_ok = false;

try {
    if (empty($_ENV['MONGO_HOST']) && is_file(dirname(__DIR__) . '/.env')) {
        Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }

    foreach (['MONGO_ROOT_USERNAME', 'MONGO_ROOT_PASSWORD', 'MONGO_HOST', 'MONGO_PORT'] as $variable) {
        if (!isset($_ENV[$variable]) || $_ENV[$variable] === '') {
            throw new RuntimeException(sprintf('Missing required environment variable: %s', $variable));
        }
    }

    $Manager = new Manager(
        sprintf(
            'mongodb://%s:%s@%s:%s/?authSource=%s',
            rawurlencode((string) $_ENV['MONGO_ROOT_USERNAME']),
            rawurlencode((string) $_ENV['MONGO_ROOT_PASSWORD']),
            $_ENV['MONGO_HOST'],
            $_ENV['MONGO_PORT'],
            rawurlencode((string) ($_ENV['MONGO_AUTH_SOURCE'] ?? 'admin'))
        ),
        [
            'serverSelectionTimeoutMS' => 1500,
            'connectTimeoutMS' => 1500,
            'socketTimeoutMS' => 1500,
        ]
    );

    $Cursor = $Manager->executeCommand('admin', new Command(['ping' => 1]));
    $result = current($Cursor->toArray());
    $mongo_ok = is_object($result) && (float) ($result->ok ?? 0) === 1.0;
} catch (Throwable $Exception) {
    error_log('MongoDB health check failed: ' . $Exception->getMessage());
}

http_response_code($mongo_ok ? 200 : 503);

echo json_encode([
    'status' => $mongo_ok ? 'ok' : 'error',
    'services' => [
        'web' => 'ok',
        'app' => 'ok',
        'db' => $mongo_ok ? 'ok' : 'error',
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
