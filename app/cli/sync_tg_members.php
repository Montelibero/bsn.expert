#!/usr/bin/env php
<?php

declare(strict_types=1);

use Montelibero\BSN\MTLA\TgMembersSync;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$Container = require dirname(__DIR__) . '/main.php';

try {
    $Container->get(TgMembersSync::class)->sync();
} catch (Throwable $Error) {
    fprintf(STDERR, "[%s] TG Members sync failed (%s, code %s).\n", date('c'), $Error::class, $Error->getCode());
    exit(1);
}
