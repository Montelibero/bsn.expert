<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use Montelibero\BSN\BSN;
use Montelibero\BSN\Knowledge\AccountReportBuilder;
use Symfony\Component\Translation\Translator;
use Throwable;

final class TelegramUpdateProcessor
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly BSN $BSN,
        private readonly AccountReportBuilder $AccountReports,
        private readonly AccountRichMessageRenderer $AccountRenderer,
        private readonly TelegramBotApiClient $BotApi,
        private readonly TelegramBotConfig $Config,
        private readonly TelegramUpdateStore $Updates,
        private readonly TelegramUsageStore $Usage,
        private readonly TelegramDailySubscriptionStore $DailySubscriptions,
        private readonly Translator $Translator,
    ) {
    }

    /**
     * Claims and processes at most one durable update.
     *
     * @return bool true when a job was claimed
     */
    public function processNext(): bool
    {
        $job = $this->Updates->claimNextDue();
        if ($job === null) {
            return false;
        }

        $payload = $job['payload'];
        $phase = $job['phase'] ?? TelegramUpdateStore::PHASE_RESPOND;
        if ($phase === TelegramUpdateStore::PHASE_RESPOND) {
            $this->setReactionBestEffort($payload, TelegramBotApiClient::REACTION_PROCESSING);
        }

        try {
            if ($phase === TelegramUpdateStore::PHASE_RECORD_USAGE) {
                $this->processPendingUsage($job, $payload);

                return true;
            }

            $type = $payload['type'] ?? null;
            if ($type === TelegramUpdateParser::TYPE_ACCOUNT_INFO) {
                $this->processAccountLookup($job, $payload);
            } elseif ($type === TelegramUpdateParser::TYPE_ACCOUNT_PROMPT) {
                $this->processAccountPrompt($job, $payload);
            } elseif ($type === TelegramUpdateParser::TYPE_ADMIN_COMMAND) {
                $this->processAdminCommand($job, $payload);
            } elseif ($type === TelegramUpdateParser::TYPE_VALIDATION_ERROR) {
                $this->processValidationError($job, $payload);
            } else {
                $this->failJob($job, 'Unsupported Telegram update type.');
            }
        } catch (TelegramBotApiException $Exception) {
            $this->handleTelegramFailure($job, $Exception);
        } catch (Throwable $Exception) {
            error_log(sprintf(
                'Telegram update processing failed: update_id=%s error=%s',
                $job['update_id'],
                $Exception::class
            ));
            $this->failJob($job, 'Unexpected processing failure: ' . $Exception::class);
        }

        return true;
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     * @param array<string, mixed> $payload
     */
    private function processAccountLookup(array $job, array $payload): void
    {
        $account_id = strtoupper(trim((string) ($payload['account_id'] ?? '')));
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            $this->failJob($job, 'Queued account lookup has an invalid account ID.');

            return;
        }

        try {
            $report = $this->AccountReports->build($account_id, $this->locale($payload));
            $rendered = $this->AccountRenderer->render($report);
            $rich_message = $rendered['rich_message'] ?? null;
            if (!is_array($rich_message) || $rich_message === []) {
                throw new \RuntimeException('Account renderer returned an empty rich message.');
            }
        } catch (Throwable $Exception) {
            error_log(sprintf(
                'Telegram account report build failed: update_id=%s error=%s',
                $job['update_id'],
                $Exception::class
            ));
            $this->deliverAccountFallback($job, $payload, $account_id);

            return;
        }

        try {
            $response = $this->BotApi->sendRichMessage(
                $this->chatId($payload),
                $rich_message,
                $this->replyOptions($payload)
            );
        } catch (TelegramBotApiException $Exception) {
            if ($Exception->deliveryMayHaveSucceeded() || $this->shouldRetry($Exception, $job['attempt_count'])) {
                throw $Exception;
            }

            error_log(sprintf(
                'Telegram rich account response rejected: update_id=%s error_code=%s',
                $job['update_id'],
                $Exception->errorCode() === null ? 'none' : (string) $Exception->errorCode()
            ));
            $this->deliverAccountFallback($job, $payload, $account_id);

            return;
        }

        $known = (bool) ($report['account']['is_known_in_bsn'] ?? false);
        $this->finishAccountResponse(
            $job,
            $payload,
            $account_id,
            'article',
            $known,
            [
                'response' => 'account_article',
                'account_id' => $account_id,
                'message_id' => $this->responseMessageId($response),
            ]
        );
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     * @param array<string, mixed> $payload
     */
    private function deliverAccountFallback(array $job, array $payload, string $account_id): void
    {
        $response = $this->sendReply(
            $payload,
            $this->trans($payload, 'telegram_bot.account_fallback')
        );
        $this->finishAccountResponse(
            $job,
            $payload,
            $account_id,
            'error',
            $this->BSN->getAccountById($account_id) !== null,
            [
                'response' => 'account_error',
                'account_id' => $account_id,
                'message_id' => $this->responseMessageId($response),
            ]
        );
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     * @param array<string, mixed> $payload
     */
    private function processAccountPrompt(array $job, array $payload): void
    {
        $response = $this->sendReply(
            $payload,
            $this->trans($payload, 'telegram_bot.account_prompt'),
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' => 'G…',
                ],
            ]
        );
        $this->completeJob($job, $payload, [
            'response' => 'account_prompt',
            'message_id' => $this->responseMessageId($response),
        ]);
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     * @param array<string, mixed> $payload
     */
    private function processValidationError(array $job, array $payload): void
    {
        $validation_error = $payload['validation_error'] ?? null;
        $help_context = $payload['help_context'] ?? null;
        if (!is_string($help_context)
            && in_array($validation_error, ['help_requested', 'invalid_start_payload'], true)
        ) {
            // Accept jobs produced by the first implementation of start/help
            // support, even if they were queued during a rolling deployment.
            $help_context = $validation_error;
        }
        if (in_array($help_context, ['help_requested', 'invalid_start_payload'], true)) {
            $this->processHelp($job, $payload, $help_context === 'invalid_start_payload');

            return;
        }

        if (($payload['validation_error'] ?? null) === 'missing_account_id'
            && ($payload['command'] ?? null) === TelegramUpdateParser::COMMAND_ACCOUNT
        ) {
            $this->processAccountPrompt($job, $payload);

            return;
        }

        if ($validation_error === 'missing_account_id') {
            $text = $this->html($this->trans($payload, 'telegram_bot.validation.missing_account_id'))
                . "\n"
                . $this->code('/account_info G…');
            $response = $this->sendReply($payload, $text, ['parse_mode' => 'HTML']);
        } else {
            $text = match ($validation_error) {
                'invalid_account_id' => $this->trans($payload, 'telegram_bot.validation.invalid_account_id'),
                'unexpected_argument' => $this->trans($payload, 'telegram_bot.validation.unexpected_argument'),
                default => $this->trans($payload, 'telegram_bot.validation.unknown_command'),
            };
            $response = $this->sendReply($payload, $text);
        }
        $this->completeJob($job, $payload, [
            'response' => 'validation_error',
            'message_id' => $this->responseMessageId($response),
        ]);
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     * @param array<string, mixed> $payload
     */
    private function processHelp(array $job, array $payload, bool $invalid_start_payload): void
    {
        $parts = [];
        if ($invalid_start_payload) {
            $parts[] = $this->html($this->trans($payload, 'telegram_bot.help.invalid_start_payload'));
        }
        $parts[] = $this->html($this->trans($payload, 'telegram_bot.help.intro'));
        $parts[] = '• ' . $this->html($this->trans($payload, 'telegram_bot.help.private_lookup'))
            . "\n"
            . $this->code('G…');
        $parts[] = '• ' . $this->html($this->trans($payload, 'telegram_bot.help.group_lookup'))
            . "\n"
            . $this->code('/account G…');
        $parts[] = '• ' . $this->html($this->trans($payload, 'telegram_bot.help.prompt_lookup'))
            . "\n"
            . $this->code('/account');
        $parts[] = '• ' . $this->html($this->trans($payload, 'telegram_bot.help.linked_accounts'));
        $parts[] = $this->html($this->trans($payload, 'telegram_bot.help.data_notice'));

        $response = $this->sendReply(
            $payload,
            implode("\n\n", $parts),
            ['parse_mode' => 'HTML']
        );
        $this->completeJob($job, $payload, [
            'response' => $invalid_start_payload ? 'help_invalid_start_payload' : 'help',
            'message_id' => $this->responseMessageId($response),
        ]);
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     * @param array<string, mixed> $payload
     */
    private function processAdminCommand(array $job, array $payload): void
    {
        $chat = is_array($payload['chat'] ?? null) ? $payload['chat'] : [];
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $chat_id = (string) ($chat['id'] ?? '');
        $user_id = (string) ($user['id'] ?? '');
        $chat_type = (string) ($chat['type'] ?? '');

        if (!$this->Config->isAdmin($user_id)
            || !$this->DailySubscriptions->canManage($chat_id, $user_id, $chat_type)
        ) {
            $response = $this->sendReply($payload, 'Эта команда доступна только администраторам бота в личном чате.');
            $this->completeJob($job, $payload, [
                'response' => 'admin_denied',
                'message_id' => $this->responseMessageId($response),
            ]);

            return;
        }

        $command = $payload['command'] ?? null;
        if ($command === TelegramUpdateParser::COMMAND_DAILY_REPORT_ON) {
            $changed = $this->DailySubscriptions->enable($chat_id, $user_id, $chat_type);
            $text = $changed
                ? 'Ежедневный отчёт включён. Он будет приходить сюда после завершения суток UTC.'
                : 'Ежедневный отчёт уже включён.';
        } elseif ($command === TelegramUpdateParser::COMMAND_DAILY_REPORT_OFF) {
            $changed = $this->DailySubscriptions->disable($chat_id, $user_id, $chat_type);
            $text = $changed
                ? 'Ежедневный отчёт выключен.'
                : 'Ежедневный отчёт уже был выключен.';
        } else {
            $this->failJob($job, 'Unsupported Telegram admin command.');

            return;
        }

        $response = $this->sendReply($payload, $text);
        $this->completeJob($job, $payload, [
            'response' => 'admin_command',
            'command' => $command,
            'changed' => $changed,
            'message_id' => $this->responseMessageId($response),
        ]);
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     */
    private function handleTelegramFailure(array $job, TelegramBotApiException $Exception): void
    {
        if ($Exception->deliveryMayHaveSucceeded()) {
            $this->Updates->deliveryUncertain(
                $job['update_id'],
                $job['lease_token'],
                $Exception->getMessage()
            );
            error_log(sprintf(
                'Telegram delivery is uncertain; update will not be retried: update_id=%s method=%s',
                $job['update_id'],
                $Exception->apiMethod()
            ));

            return;
        }

        if ($this->shouldRetry($Exception, $job['attempt_count'])) {
            $this->Updates->retry(
                $job['update_id'],
                $job['lease_token'],
                $Exception->getMessage(),
                $this->retryDelay($Exception, $job['attempt_count'])
            );

            return;
        }

        $this->failJob($job, $Exception->getMessage());
    }

    private function shouldRetry(TelegramBotApiException $Exception, int $attempt_count): bool
    {
        if ($attempt_count >= self::MAX_ATTEMPTS) {
            return false;
        }

        $error_code = $Exception->errorCode();
        $http_status = $Exception->httpStatus();

        return $Exception->retryAfterSeconds() !== null
            || $error_code === 429
            || ($error_code !== null && $error_code >= 500)
            || ($http_status !== null && $http_status >= 500);
    }

    private function retryDelay(TelegramBotApiException $Exception, int $attempt_count): int
    {
        $retry_after = $Exception->retryAfterSeconds();
        if ($retry_after !== null) {
            // Telegram defines this as the exact number of seconds to wait;
            // shortening a long flood wait can exhaust every retry too early.
            return max(1, $retry_after);
        }

        return min(900, 15 * (2 ** max(0, $attempt_count - 1)));
    }

    /**
     * @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job
     * @param array<string, mixed> $payload
     */
    private function completeJob(array $job, array $payload, array $effect, bool $clear_reaction = true): void
    {
        if (!$this->Updates->complete($job['update_id'], $job['lease_token'], $effect)) {
            throw new \RuntimeException('Telegram update lease was lost before completion.');
        }
        if ($clear_reaction) {
            $this->clearReactionBestEffort($payload);
        }
    }

    /** @param array{update_id: string, lease_token: string, attempt_count: int, payload: array<string, mixed>} $job */
    private function failJob(array $job, string $error): void
    {
        $this->Updates->fail($job['update_id'], $job['lease_token'], $error);
    }

    /** @param array<string, mixed> $payload */
    private function sendReply(array $payload, string $text, array $extra_options = []): array
    {
        $options = $this->replyOptions($payload);
        if (array_intersect_key($options, $extra_options) !== []) {
            throw new \InvalidArgumentException('A Telegram reply option was provided twice.');
        }

        return $this->BotApi->sendMessage(
            $this->chatId($payload),
            $text,
            array_merge($options, $extra_options)
        );
    }

    /** @param array<string, mixed> $payload */
    private function locale(array $payload): string
    {
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $language_code = strtolower(trim((string) ($user['language_code'] ?? '')));

        return $language_code === 'ru' || str_starts_with($language_code, 'ru-') ? 'ru' : 'en';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, scalar> $parameters
     */
    private function trans(array $payload, string $key, array $parameters = []): string
    {
        return $this->Translator->trans($key, $parameters, null, $this->locale($payload));
    }

    private function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function code(string $text): string
    {
        return '<code>' . $this->html($text) . '</code>';
    }

    /** @param array<string, mixed> $payload */
    private function chatId(array $payload): string
    {
        $chat = is_array($payload['chat'] ?? null) ? $payload['chat'] : [];
        $chat_id = trim((string) ($chat['id'] ?? ''));
        if ($chat_id === '') {
            throw new \InvalidArgumentException('Queued Telegram update has no chat ID.');
        }

        return $chat_id;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function replyOptions(array $payload): array
    {
        $message_id = $payload['message_id'] ?? null;
        if (!is_int($message_id) || $message_id <= 0) {
            throw new \InvalidArgumentException('Queued Telegram update has no message ID.');
        }

        $options = [
            'reply_parameters' => [
                'message_id' => $message_id,
                'allow_sending_without_reply' => true,
            ],
        ];
        $thread_id = $payload['message_thread_id'] ?? null;
        if (is_int($thread_id) && $thread_id > 0) {
            $options['message_thread_id'] = $thread_id;
        }
        $direct_topic_id = $this->positiveInteger($payload['direct_messages_topic_id'] ?? null);
        if ($direct_topic_id !== null) {
            $options['direct_messages_topic_id'] = $direct_topic_id;
        }

        return $options;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/\A[1-9]\d*\z/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    /** @param array<string, mixed> $payload */
    private function setReactionBestEffort(array $payload, string $emoji): void
    {
        try {
            $message_id = $payload['message_id'] ?? null;
            if (!is_int($message_id) || $message_id <= 0) {
                return;
            }
            $this->BotApi->setMessageReaction($this->chatId($payload), $message_id, $emoji);
        } catch (Throwable $Exception) {
            error_log(sprintf(
                'Telegram reaction failed: update_id=%s error=%s',
                (string) ($payload['update_id'] ?? 'unknown'),
                $Exception::class
            ));
        }
    }

    /** @param array<string, mixed> $payload */
    private function clearReactionBestEffort(array $payload): void
    {
        try {
            $message_id = $payload['message_id'] ?? null;
            if (!is_int($message_id) || $message_id <= 0) {
                return;
            }
            $this->BotApi->clearMessageReaction($this->chatId($payload), $message_id);
        } catch (Throwable $Exception) {
            error_log(sprintf(
                'Telegram reaction clearing failed: update_id=%s error=%s',
                (string) ($payload['update_id'] ?? 'unknown'),
                $Exception::class
            ));
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $effect
     */
    private function finishAccountResponse(
        array $job,
        array $payload,
        string $account_id,
        string $outcome,
        bool $known,
        array $effect,
    ): void {
        $chat = is_array($payload['chat'] ?? null) ? $payload['chat'] : [];
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : null;
        $message_date = $payload['message_date'] ?? null;
        $usage = [
            'message_date' => is_int($message_date) && $message_date > 0 ? $message_date : time(),
            'chat' => $chat,
            'user' => $user,
            'account_id' => $account_id,
            'outcome' => $outcome,
            'known' => $known,
        ];

        if (!$this->Updates->markUsagePending(
            $job['update_id'],
            $job['lease_token'],
            $usage,
            $effect
        )) {
            throw new \RuntimeException('Telegram update lease was lost after response delivery.');
        }

        $job['phase'] = TelegramUpdateStore::PHASE_RECORD_USAGE;
        $job['pending_usage'] = $usage;
        $job['pending_effect'] = $effect;
        $this->processPendingUsage($job, $payload);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     */
    private function processPendingUsage(array $job, array $payload): void
    {
        $usage = $job['pending_usage'] ?? null;
        if (!is_array($usage)) {
            $this->failJob($job, 'Queued Telegram usage phase has no payload.');

            return;
        }

        $this->clearReactionBestEffort($payload);

        try {
            $chat = is_array($usage['chat'] ?? null) ? $usage['chat'] : [];
            $user = is_array($usage['user'] ?? null) ? $usage['user'] : null;
            $this->Usage->recordAccountLookup(
                $job['update_id'],
                (int) ($usage['message_date'] ?? 0),
                $chat,
                $user,
                (string) ($usage['account_id'] ?? ''),
                (string) ($usage['outcome'] ?? ''),
                ($usage['known'] ?? false) === true
            );
        } catch (Throwable $Exception) {
            error_log(sprintf(
                'Telegram usage write deferred: update_id=%s error=%s',
                $job['update_id'],
                $Exception::class
            ));
            $this->Updates->retry(
                $job['update_id'],
                $job['lease_token'],
                'Usage recording failed: ' . $Exception::class,
                60
            );

            return;
        }

        $effect = is_array($job['pending_effect'] ?? null) ? $job['pending_effect'] : [];
        $this->completeJob(
            $job,
            $payload,
            $effect,
            false
        );
    }

    /** @param array<string, mixed> $response */
    private function responseMessageId(array $response): ?int
    {
        return is_int($response['message_id'] ?? null) && $response['message_id'] > 0
            ? $response['message_id']
            : null;
    }
}
