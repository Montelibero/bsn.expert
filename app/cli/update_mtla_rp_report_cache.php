#!/usr/bin/env php
<?php

declare(strict_types=1);

use DI\Container;
use Montelibero\BSN\MTLA\MtlaProgramReportService;

const REFRESH_AFTER_SECONDS = 259200;

/** @var Container $Container */
$Container = require dirname(__DIR__) . '/main.php';

if (IS_CLI_CONTEXT !== true) {
    die("This script can only run in CLI mode.\n");
}

/** @var MtlaProgramReportService $ReportService */
$ReportService = $Container->get(MtlaProgramReportService::class);

$programs = $ReportService->collectPrograms();
$program_account_ids = array_map(
    static fn(array $item): string => $item['data']['id'],
    $programs['items']
);

$snapshot = $ReportService->fetchMtlaSnapshot($program_account_ids, false);
$age_seconds = $snapshot['age_seconds'] ?? null;
$warning = $snapshot['warning'] ?? null;
$from_cache = (bool) ($snapshot['from_cache'] ?? false);

if ($warning !== null && $age_seconds === null) {
    fwrite(STDERR, sprintf("MTLA RP report cache unavailable: %s\n", $warning));
    exit(1);
}

if (!$from_cache) {
    printf(
        "MTLA RP report cache rebuilt automatically. programs=%d age=%s\n",
        count($program_account_ids),
        formatAge($age_seconds)
    );
    exit(0);
}

if ($age_seconds !== null && $age_seconds <= REFRESH_AFTER_SECONDS) {
    printf(
        "MTLA RP report cache is fresh enough. age=%s threshold=%s programs=%d\n",
        formatAge($age_seconds),
        formatAge(REFRESH_AFTER_SECONDS),
        count($program_account_ids)
    );
    exit(0);
}

printf(
    "Refreshing MTLA RP report cache. current_age=%s threshold=%s programs=%d\n",
    formatAge($age_seconds),
    formatAge(REFRESH_AFTER_SECONDS),
    count($program_account_ids)
);

$snapshot = $ReportService->fetchMtlaSnapshot($program_account_ids, true);
$warning = $snapshot['warning'] ?? null;
if ($warning !== null) {
    fwrite(STDERR, sprintf("MTLA RP report cache refresh failed: %s\n", $warning));
    exit(1);
}

printf(
    "MTLA RP report cache refreshed successfully. age=%s programs=%d\n",
    formatAge($snapshot['age_seconds'] ?? null),
    count($program_account_ids)
);

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
