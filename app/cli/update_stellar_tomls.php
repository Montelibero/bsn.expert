#!/usr/bin/env php
<?php

declare(strict_types=1);

use DI\Container;
use Montelibero\BSN\StellarTomlCrawler;

/** @var Container $Container */
$Container = require dirname(__DIR__) . '/main.php';

if (IS_CLI_CONTEXT !== true) {
    die("This script can only run in CLI mode.\n");
}

/** @var StellarTomlCrawler $Crawler */
$Crawler = $Container->get(StellarTomlCrawler::class);
$summary = $Crawler->runAll(static function (string $message): void {
    fwrite(STDOUT, '[' . date('c') . '] ' . $message . PHP_EOL);
});

printf(
    "stellar.toml crawl finished: status=%s accounts=%d accounts_with_home_domain=%d domains=%d mtlap_holders=%d mtlac_holders=%d issuer_requests=%d issuer_skipped=%d requested=%d ok=%d errors=%d ignored=%d unchanged=%d duration=%ss\n",
    $summary['status'],
    $summary['accounts_seen'],
    $summary['accounts_with_home_domain'],
    $summary['home_domains_seen'],
    $summary['mtlap_holders'],
    $summary['mtlac_holders'],
    $summary['known_token_issuer_horizon_requests'],
    $summary['known_token_issuer_horizon_skipped'],
    $summary['home_domains_requested'],
    $summary['home_domains_ok'],
    $summary['home_domains_error'],
    $summary['home_domains_ignored'],
    $summary['home_domains_unchanged'],
    $summary['duration_seconds']
);

if (($summary['status'] ?? null) !== 'ok') {
    foreach (($summary['errors'] ?? []) as $error) {
        fwrite(STDERR, sprintf(
            "[%s] %s\n",
            $error['stage'] ?? 'unknown',
            $error['message'] ?? 'unknown error'
        ));
    }
}
