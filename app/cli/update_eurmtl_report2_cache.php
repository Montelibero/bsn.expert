#!/usr/bin/env php
<?php

declare(strict_types=1);

use DI\Container;
use Montelibero\BSN\EurmtlReport2Service;

const REFRESH_AFTER_SECONDS = 86400;

/** @var Container $Container */
$Container = require dirname(__DIR__) . '/main.php';

if (IS_CLI_CONTEXT !== true) {
    die("This script can only run in CLI mode.\n");
}

/** @var EurmtlReport2Service $ReportService */
$ReportService = $Container->get(EurmtlReport2Service::class);

$snapshot = $ReportService->fetchSnapshot(false);
$age_seconds = $snapshot['age_seconds'] ?? null;
$warning = $snapshot['warning'] ?? null;
$from_cache = (bool) ($snapshot['from_cache'] ?? false);

if ($warning !== null && $age_seconds === null) {
    fwrite(STDERR, sprintf("EURMTL report2 cache unavailable: %s\n", $warning));
    exit(1);
}

if (!$from_cache) {
    printf(
        "EURMTL report2 cache rebuilt automatically. market=%s age=%s\n",
        metric($snapshot, 'market_eurmtl'),
        formatAge($age_seconds)
    );
    exit(0);
}

if ($age_seconds !== null && $age_seconds <= REFRESH_AFTER_SECONDS) {
    printf(
        "EURMTL report2 cache is fresh enough. age=%s threshold=%s market=%s\n",
        formatAge($age_seconds),
        formatAge(REFRESH_AFTER_SECONDS),
        metric($snapshot, 'market_eurmtl')
    );
    exit(0);
}

printf(
    "Refreshing EURMTL report2 cache. current_age=%s threshold=%s\n",
    formatAge($age_seconds),
    formatAge(REFRESH_AFTER_SECONDS)
);

$snapshot = $ReportService->fetchSnapshot(true);
$warning = $snapshot['warning'] ?? null;
if ($warning !== null) {
    fwrite(STDERR, sprintf("EURMTL report2 cache refresh failed: %s\n", $warning));
    exit(1);
}

printf(
    "EURMTL report2 cache refreshed successfully. age=%s market=%s\n",
    formatAge($snapshot['age_seconds'] ?? null),
    metric($snapshot, 'market_eurmtl')
);

function metric(array $snapshot, string $key): string
{
    return (string) ($snapshot['metrics'][$key] ?? '0');
}

function formatAge(?int $seconds): string
{
    if ($seconds === null) {
        return 'unknown';
    }

    if ($seconds < 60) {
        return $seconds . 's';
    }

    if ($seconds < 3600) {
        return round($seconds / 60, 1) . 'm';
    }

    if ($seconds < 86400) {
        return round($seconds / 3600, 1) . 'h';
    }

    return round($seconds / 86400, 2) . 'd';
}
