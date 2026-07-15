#!/usr/bin/env php
<?php

declare(strict_types=1);

use DI\Container;
use Montelibero\BSN\GristSyncJobManager;
use Montelibero\BSN\GristSyncService;

/** @var Container $Container */
$Container = require dirname(__DIR__) . '/main.php';

if (IS_CLI_CONTEXT !== true) {
    fwrite(STDERR, "This script can only run in CLI mode.\n");
    exit(1);
}

/** @var GristSyncJobManager $Jobs */
$Jobs = $Container->get(GristSyncJobManager::class);
/** @var GristSyncService $Sync */
$Sync = $Container->get(GristSyncService::class);

if (in_array('--schedule-all', $_SERVER['argv'] ?? [], true)) {
    foreach (GristSyncService::scopes() as $scope) {
        $Jobs->schedule($scope, 60);
        printf("[%s] scheduled periodic Grist reconciliation: scope=%s delay=60s\n", date('c'), $scope);
    }
}

$failed = false;
while ($job = $Jobs->claimNextDue()) {
    $scope = $job['scope'];
    $revision = $job['revision'];

    try {
        printf("[%s] starting Grist sync: scope=%s revision=%d\n", date('c'), $scope, $revision);
        $result = $Sync->sync($scope);
        $Jobs->complete($scope, $revision, $result);
        printf(
            "[%s] Grist sync completed: scope=%s revision=%d result=%s\n",
            date('c'),
            $scope,
            $revision,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    } catch (Throwable $Exception) {
        $failed = true;
        $Jobs->fail($scope, $revision, $Exception);
        fwrite(STDERR, sprintf(
            "[%s] Grist sync failed: scope=%s revision=%d error=%s\n",
            date('c'),
            $scope,
            $revision,
            $Exception->getMessage()
        ));
    }
}

exit($failed ? 1 : 0);
