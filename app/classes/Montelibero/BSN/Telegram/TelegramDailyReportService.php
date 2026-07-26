<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class TelegramDailyReportService
{
    public const ADMIN_SUMMARY_DAYS = 14;
    public const DEFAULT_FINALIZATION_LOOKBACK_DAYS = TelegramUsageStore::RAW_RETENTION_DAYS - 1;
    public const DEFAULT_MAX_DELIVERIES = 1000;

    public function __construct(
        private readonly TelegramUsageStore $UsageStore,
        private readonly TelegramUsageAggregator $Aggregator,
        private readonly TelegramDailySubscriptionStore $SubscriptionStore,
        private readonly TelegramDailyReportRenderer $Renderer,
    ) {
    }

    /** @return array<string, mixed> */
    public function aggregateDay(string $day_utc): array
    {
        TelegramUsageStore::assertDay($day_utc);

        return $this->Aggregator->aggregate($day_utc, $this->UsageStore->eventsForDay($day_utc));
    }

    /**
     * @return array{
     *     created: bool,
     *     summary: array{day_utc: string, requests: int, unique_chats: int, unique_users: int, unique_accounts: int}
     * }
     */
    public function finalizeDay(
        string $day_utc,
        ?DateTimeImmutable $finalized_at = null,
    ): array {
        $candidate = $this->Aggregator->anonymizedSummary($this->aggregateDay($day_utc));
        $created = $this->UsageStore->finalizeSummary($candidate, $finalized_at);
        $stored = $this->UsageStore->summaryForDay($day_utc);

        return [
            'created' => $created,
            'summary' => $this->summaryValues($stored ?? $candidate),
        ];
    }

    /**
     * Refreshes completed UTC days while their full detailed day is retained.
     * Today is never finalized, so its totals keep changing during the day.
     *
     * @return list<string> created or refreshed UTC days
     */
    public function finalizeCompletedDays(
        ?DateTimeImmutable $now = null,
        int $lookback_days = self::DEFAULT_FINALIZATION_LOOKBACK_DAYS,
    ): array {
        if ($lookback_days < 1 || $lookback_days >= TelegramUsageStore::RAW_RETENTION_DAYS) {
            throw new \InvalidArgumentException(sprintf(
                'Telegram finalization lookback must be between 1 and %d days.',
                TelegramUsageStore::RAW_RETENTION_DAYS - 1
            ));
        }

        $Today = $this->startOfUtcDay($now);
        $finalized_days = [];
        for ($offset = $lookback_days; $offset >= 1; $offset--) {
            $day_utc = $Today->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $result = $this->finalizeDay($day_utc, $this->utc($now));
            if ($result['created']) {
                $finalized_days[] = $day_utc;
            }
        }

        return $finalized_days;
    }

    /**
     * Returns 14 UTC days including today, newest first. The whole range is
     * inside detailed retention, so totals are calculated live.
     *
     * @return list<array{
     *     day_utc: string,
     *     requests: int,
     *     unique_chats: int,
     *     unique_users: int,
     *     unique_accounts: int,
     *     finalized: bool
     * }>
     */
    public function adminSummaries(?DateTimeImmutable $now = null): array
    {
        $Today = $this->startOfUtcDay($now);
        $from_day_utc = $Today->modify('-' . (self::ADMIN_SUMMARY_DAYS - 1) . ' days')->format('Y-m-d');
        $today_utc = $Today->format('Y-m-d');
        $stored = $this->UsageStore->summariesBetween($from_day_utc, $today_utc);
        $rows = [];

        for ($offset = 0; $offset < self::ADMIN_SUMMARY_DAYS; $offset++) {
            $day_utc = $Today->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $finalized = $day_utc !== $today_utc && isset($stored[$day_utc]);
            $summary = $this->Aggregator->anonymizedSummary($this->aggregateDay($day_utc));
            $rows[] = $summary + ['finalized' => $finalized];
        }

        return $rows;
    }

    /**
     * @return array{
     *     day_utc: string,
     *     finalized: bool,
     *     details_available: bool,
     *     summary: array{day_utc: string, requests: int, unique_chats: int, unique_users: int, unique_accounts: int},
     *     aggregate: array<string, mixed>
     * }
     */
    public function adminDayDetails(string $day_utc, ?DateTimeImmutable $now = null): array
    {
        TelegramUsageStore::assertDay($day_utc);
        $aggregate = $this->aggregateDay($day_utc);
        $live_summary = $this->Aggregator->anonymizedSummary($aggregate);
        $stored = $this->UsageStore->summaryForDay($day_utc);
        $OldestDetailedDay = $this->startOfUtcDay($now)
            ->modify('-' . (TelegramUsageStore::RAW_RETENTION_DAYS - 1) . ' days');
        $details_available = $day_utc >= $OldestDetailedDay->format('Y-m-d');
        $summary = $details_available || $stored === null
            ? $live_summary
            : $this->summaryValues($stored);

        return [
            'day_utc' => $day_utc,
            'finalized' => $stored !== null,
            'details_available' => $details_available,
            'summary' => $summary,
            'aggregate' => $aggregate,
        ];
    }

    /**
     * Finalizes completed days and delivers the previous UTC day's report to
     * every due subscription under an atomic lease.
     *
     * The sender must have this signature:
     * callable(string $chat_id, array $rich_message, array $options): array.
     * The options always contain disable_notification=true.
     *
     * @param callable(string, array<string, mixed>, array{disable_notification: true}): array<string, mixed> $sender
     * @return array{
     *     day_utc: string,
     *     finalized_days: list<string>,
     *     sent: int,
     *     failed: int,
     *     uncertain: int,
     *     completion_conflicts: int,
     *     limit_reached: bool,
     *     totals: array<string, mixed>,
     *     report_stats: array<string, mixed>
     * }
     */
    public function runDue(
        callable $sender,
        ?DateTimeImmutable $now = null,
        int $finalization_lookback_days = self::DEFAULT_FINALIZATION_LOOKBACK_DAYS,
        int $max_deliveries = self::DEFAULT_MAX_DELIVERIES,
        bool $refresh_summaries = true,
    ): array {
        if ($max_deliveries < 1) {
            throw new \InvalidArgumentException('Telegram daily report delivery limit must be positive.');
        }

        $Now = $this->utc($now);
        $day_utc = $this->startOfUtcDay($Now)->modify('-1 day')->format('Y-m-d');
        $finalized_days = $refresh_summaries
            ? $this->finalizeCompletedDays($Now, $finalization_lookback_days)
            : [];
        $aggregate = $this->aggregateDay($day_utc);
        $rendered = $this->Renderer->render($aggregate, $Now);
        $rich_message = is_array($rendered['rich_message'] ?? null) ? $rendered['rich_message'] : [];
        $report_stats = is_array($rendered['stats'] ?? null) ? $rendered['stats'] : [];
        if ($rich_message === []) {
            throw new \RuntimeException('Telegram daily report renderer returned an empty message.');
        }

        $sent = 0;
        $failed = 0;
        $uncertain = 0;
        $completion_conflicts = 0;
        $attempts = 0;
        while ($attempts < $max_deliveries) {
            $claim = $this->SubscriptionStore->claimNextDue($day_utc, $Now);
            if ($claim === null) {
                break;
            }
            $attempts++;

            try {
                $response = $sender(
                    $claim['chat_id'],
                    $rich_message,
                    ['disable_notification' => true]
                );
                $message_id = $response['message_id'] ?? null;
                if (!is_int($message_id) && !is_string($message_id)) {
                    $message_id = null;
                }
                if ($this->SubscriptionStore->completeDelivery(
                    $claim['chat_id'],
                    $day_utc,
                    $claim['claim_token'],
                    $message_id,
                    $Now
                )) {
                    $sent++;
                } else {
                    $completion_conflicts++;
                }
            } catch (Throwable $Exception) {
                if ($Exception instanceof TelegramBotApiException
                    && $Exception->deliveryMayHaveSucceeded()
                ) {
                    if ($this->SubscriptionStore->deliveryUncertain(
                        $claim['chat_id'],
                        $day_utc,
                        $claim['claim_token'],
                        $Exception->errorCode(),
                        $Now
                    )) {
                        $uncertain++;
                    } else {
                        $completion_conflicts++;
                    }
                    continue;
                }

                $retry_seconds = 15 * 60;
                $error_code = null;
                if ($Exception instanceof TelegramBotApiException) {
                    $retry_seconds = max(1, $Exception->retryAfterSeconds() ?? $retry_seconds);
                    $error_code = $Exception->errorCode();
                }
                $this->SubscriptionStore->failDelivery(
                    $claim['chat_id'],
                    $day_utc,
                    $claim['claim_token'],
                    $error_code,
                    $Now->modify(sprintf('+%d seconds', $retry_seconds)),
                    $Now
                );
                $failed++;
            }
        }

        $totals = is_array($aggregate['totals'] ?? null) ? $aggregate['totals'] : [];

        return [
            'day_utc' => $day_utc,
            'finalized_days' => $finalized_days,
            'sent' => $sent,
            'failed' => $failed,
            'uncertain' => $uncertain,
            'completion_conflicts' => $completion_conflicts,
            'limit_reached' => $attempts >= $max_deliveries,
            'totals' => $totals,
            'report_stats' => $report_stats,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{day_utc: string, requests: int, unique_chats: int, unique_users: int, unique_accounts: int}
     */
    private function summaryValues(array $summary): array
    {
        $day_utc = (string) ($summary['day_utc'] ?? $summary['_id'] ?? '');
        TelegramUsageStore::assertDay($day_utc);

        return [
            'day_utc' => $day_utc,
            'requests' => max(0, (int) ($summary['requests'] ?? 0)),
            'unique_chats' => max(0, (int) ($summary['unique_chats'] ?? 0)),
            'unique_users' => max(0, (int) ($summary['unique_users'] ?? 0)),
            'unique_accounts' => max(0, (int) ($summary['unique_accounts'] ?? 0)),
        ];
    }

    private function startOfUtcDay(?DateTimeImmutable $Date): DateTimeImmutable
    {
        return $this->utc($Date)->setTime(0, 0);
    }

    private function utc(?DateTimeImmutable $Date): DateTimeImmutable
    {
        return ($Date ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
