#!/usr/bin/env php
<?php

declare(strict_types=1);

use Montelibero\BSN\ApplicationContext;
use Montelibero\BSN\Telegram\TelegramBotApiClient;
use Montelibero\BSN\Telegram\TelegramBotConfig;
use Montelibero\BSN\Telegram\TelegramDailyReportService;
use Montelibero\BSN\Telegram\TelegramUpdateProcessor;

/** @var ApplicationContext $App */
$App = require dirname(__DIR__) . '/bootstrap.php';

if (IS_CLI_CONTEXT !== true) {
    fwrite(STDERR, "This script can only run in CLI mode.\n");
    exit(1);
}

/** @var TelegramBotConfig $Config */
$Config = $App->Container->get(TelegramBotConfig::class);
try {
    $Config->validate();
} catch (Throwable $Exception) {
    fwrite(STDERR, '[telegram-worker] Invalid Telegram configuration: ' . $Exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @var TelegramUpdateProcessor $Processor */
$Processor = $App->Container->get(TelegramUpdateProcessor::class);
/** @var TelegramDailyReportService $DailyReports */
$DailyReports = $App->Container->get(TelegramDailyReportService::class);
/** @var TelegramBotApiClient $BotApi */
$BotApi = $App->Container->get(TelegramBotApiClient::class);

$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

$last_daily_check_at = 0;
$last_summary_refresh_day = null;

while ($running) {
    $processed = 0;

    try {
        $App->BSN->refreshFromJsonFileIfChanged($App->BsnJsonPath);
        $App->GristRuntimeData->refreshMtlaMembersIfNeeded();

        while ($processed < 100 && $Processor->processNext()) {
            $processed++;
        }

        $now_timestamp = time();
        $Now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $after_daily_cutoff = $Now->format('H:i') >= '00:10';
        if ($after_daily_cutoff && $now_timestamp - $last_daily_check_at >= 60) {
            $today_utc = $Now->format('Y-m-d');
            $refresh_summaries = $last_summary_refresh_day !== $today_utc;
            // Keep a persistent failure from hammering Mongo or Telegram once
            // per worker loop; the next scheduler attempt is at least a minute later.
            $last_daily_check_at = $now_timestamp;

            $DailyReports->runDue(
                static fn(string $chat_id, array $rich_message, array $options): array =>
                    $BotApi->sendRichMessage($chat_id, $rich_message, $options),
                $Now,
                TelegramDailyReportService::DEFAULT_FINALIZATION_LOOKBACK_DAYS,
                TelegramDailyReportService::DEFAULT_MAX_DELIVERIES,
                $refresh_summaries
            );
            if ($refresh_summaries) {
                $last_summary_refresh_day = $today_utc;
            }
        }
    } catch (Throwable $Exception) {
        error_log(sprintf(
            'Telegram worker iteration failed: error=%s',
            $Exception::class
        ));
    }

    usleep($processed > 0 ? 100_000 : 1_000_000);
}

exit(0);
