<?php

namespace Montelibero\BSN\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\GristSnapshotStore;
use Montelibero\BSN\GristSyncJobManager;
use Montelibero\BSN\GristSyncService;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarTomlCrawler;
use Montelibero\BSN\StellarTomlManager;
use Montelibero\BSN\Telegram\TelegramBotApiClient;
use Montelibero\BSN\Telegram\TelegramBotConfig;
use Montelibero\BSN\Telegram\TelegramDailyReportService;
use Montelibero\BSN\Telegram\TelegramUsageStore;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class AdminController
{
    public function __construct(
        private Environment $Twig,
        private CurrentUser $CurrentUser,
        private StellarTomlManager $TomlManager,
        private StellarTomlCrawler $TomlCrawler,
        private GristSyncService $GristSyncService,
        private GristSyncJobManager $GristSyncJobs,
        private GristSnapshotStore $GristSnapshots,
        private DocumentsManager $DocumentsManager,
        private RequestSession $RequestSession,
        private TelegramBotConfig $TelegramConfig,
        private TelegramBotApiClient $TelegramBotApi,
        private TelegramDailyReportService $TelegramReports,
    ) {
    }

    public function Index(): ?string
    {
        if (!$this->isAdmin()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        return $this->Twig->render('admin.twig');
    }

    public function Tomls(): ?string
    {
        if (!$this->isAdmin()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $notice = null;
        $csrf_token = $this->csrfToken();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) {
                SimpleRouter::response()->httpCode(400);
                return 'Bad CSRF token';
            }

            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'refresh_domain') {
                $home_domain = trim((string) ($_POST['home_domain'] ?? ''));
                $result = $this->TomlCrawler->refreshDomain($home_domain, [], true);
                $notice = [
                    'type' => ($result['status'] ?? null) === 'ok' ? 'success' : (($result['status'] ?? null) === 'ignored' ? 'warning' : 'danger'),
                    'text' => $this->formatRefreshResult($result),
                ];
            } elseif ($action === 'refresh_account') {
                $account_id = strtoupper(trim((string) ($_POST['account_id'] ?? '')));
                $result = $this->TomlCrawler->refreshAccount($account_id, true);
                $notice = [
                    'type' => ($result['status'] ?? null) === 'ok' ? 'success' : (($result['status'] ?? null) === 'ignored' ? 'warning' : 'danger'),
                    'text' => $this->formatRefreshResult($result),
                ];
            } elseif ($action === 'ignore_domain') {
                $home_domain = trim((string) ($_POST['home_domain'] ?? ''));
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $doc = $this->TomlManager->setDomainIgnored(
                    $home_domain,
                    true,
                    $this->CurrentUser->getAccountId(),
                    $reason
                );
                $notice = $doc === null
                    ? ['type' => 'danger', 'text' => 'Некорректный домен']
                    : ['type' => 'warning', 'text' => $doc['home_domain'] . ' добавлен в игнор'];
            } elseif ($action === 'unignore_domain') {
                $home_domain = trim((string) ($_POST['home_domain'] ?? ''));
                $doc = $this->TomlManager->setDomainIgnored(
                    $home_domain,
                    false,
                    $this->CurrentUser->getAccountId()
                );
                $notice = $doc === null
                    ? ['type' => 'danger', 'text' => 'Некорректный домен']
                    : ['type' => 'success', 'text' => $doc['home_domain'] . ' удален из игнора'];
            }
        }

        return $this->Twig->render('admin_tomls.twig', [
            'dashboard' => $this->TomlManager->fetchDashboardData(),
            'notice' => $notice,
            'csrf_token' => $csrf_token,
        ]);
    }

    public function Caches(): ?string
    {
        if (!$this->isAdmin()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $notice = null;
        $csrf_token = $this->csrfToken('admin_caches');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) {
                SimpleRouter::response()->httpCode(400);
                return 'Bad CSRF token';
            }

            $scope = (string) ($_POST['scope'] ?? '');
            try {
                GristSyncService::assertScope($scope);
                $satisfied_revision = $this->GristSyncJobs->status($scope)['revision'];
                $result = $this->GristSyncService->sync($scope);
                $this->GristSyncJobs->recordManualSuccess($scope, $result, $satisfied_revision);
                $notice = [
                    'type' => 'success',
                    'text' => $this->formatGristSyncResult($scope, $result),
                ];
            } catch (\InvalidArgumentException) {
                SimpleRouter::response()->httpCode(400);
                return 'Unknown Grist sync scope';
            } catch (\Throwable $Exception) {
                if (in_array($scope, GristSyncService::scopes(), true)) {
                    $this->GristSyncJobs->recordManualFailure($scope, $Exception);
                }
                $notice = [
                    'type' => 'danger',
                    'text' => 'Не удалось обновить данные: ' . $Exception->getMessage(),
                ];
            }
        }

        return $this->Twig->render('admin_caches.twig', [
            'caches' => $this->gristCacheRows(),
            'notice' => $notice,
            'csrf_token' => $csrf_token,
        ]);
    }

    public function Telegram(): ?string
    {
        if (!$this->isAdmin()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $notice = null;
        $csrf_token = $this->csrfToken('admin_telegram');
        $configuration_error = null;
        try {
            $this->TelegramConfig->validate();
        } catch (\Throwable $Exception) {
            $configuration_error = $Exception->getMessage();
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) {
                SimpleRouter::response()->httpCode(400);
                return 'Bad CSRF token';
            }
            if ((string) ($_POST['action'] ?? '') !== 'register_webhook') {
                SimpleRouter::response()->httpCode(400);
                return 'Unknown Telegram admin action';
            }

            try {
                $this->TelegramConfig->validate();
                $this->TelegramBotApi->setWebhook($this->TelegramConfig);
                $this->TelegramBotApi->setMyCommands();
                $notice = [
                    'type' => 'success',
                    'text' => 'Webhook Telegram зарегистрирован, меню команд обновлено.',
                ];
                $configuration_error = null;
            } catch (\Throwable $Exception) {
                $notice = [
                    'type' => 'danger',
                    'text' => $Exception->getMessage(),
                ];
            }
        }

        $webhook = [
            'available' => false,
            'registered' => false,
            'url' => null,
            'pending_update_count' => 0,
            'max_connections' => null,
            'allowed_updates' => [],
            'bot_username' => null,
            'bot_matches_config' => false,
            'last_error_message' => null,
            'last_error_at' => null,
            'error' => null,
        ];
        if ($this->TelegramBotApi->isConfigured()) {
            try {
                $identity = $this->TelegramBotApi->getMe();
                $actual_bot_username = is_string($identity['username'] ?? null)
                    ? ltrim(trim($identity['username']), '@')
                    : '';
                $bot_matches_config = ($identity['is_bot'] ?? false) === true
                    && $actual_bot_username !== ''
                    && strcasecmp($actual_bot_username, $this->TelegramConfig->botUsername()) === 0;
                $info = $this->TelegramBotApi->getWebhookInfo();
                $actual_url = is_string($info['url'] ?? null) ? $info['url'] : '';
                $allowed_updates = is_array($info['allowed_updates'] ?? null)
                    ? array_values(array_filter(
                        $info['allowed_updates'],
                        static fn(mixed $value): bool => is_string($value)
                    ))
                    : [];
                // Telegram omits/empties allowed_updates when all update types
                // are enabled. Otherwise message must be explicitly present.
                $messages_allowed = $allowed_updates === []
                    || in_array('message', $allowed_updates, true);
                $last_error_date = is_int($info['last_error_date'] ?? null)
                    ? $info['last_error_date']
                    : null;
                $webhook = [
                    'available' => true,
                    'registered' => $actual_url === $this->TelegramConfig->webhookUrl()
                        && $messages_allowed
                        && $bot_matches_config,
                    'url' => $actual_url,
                    'pending_update_count' => max(0, (int) ($info['pending_update_count'] ?? 0)),
                    'max_connections' => isset($info['max_connections'])
                        ? max(0, (int) $info['max_connections'])
                        : null,
                    'allowed_updates' => $allowed_updates,
                    'bot_username' => $actual_bot_username === '' ? null : $actual_bot_username,
                    'bot_matches_config' => $bot_matches_config,
                    'last_error_message' => is_string($info['last_error_message'] ?? null)
                        ? $info['last_error_message']
                        : null,
                    'last_error_at' => $last_error_date === null
                        ? null
                        : gmdate('d.m.Y H:i:s', $last_error_date),
                    'error' => null,
                ];
            } catch (\Throwable $Exception) {
                $webhook['error'] = $Exception->getMessage();
            }
        } else {
            $webhook['error'] = 'TG_BOT_KEY не задан или имеет неверный формат.';
        }

        $Now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $days = array_map(
            static fn(array $summary): array => [
                'day' => (string) $summary['day_utc'],
                'label' => DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    (string) $summary['day_utc'],
                    new DateTimeZone('UTC')
                )?->format('d.m.Y') ?? (string) $summary['day_utc'],
                'is_today' => $summary['day_utc'] === $Now->format('Y-m-d'),
                'requests' => (int) $summary['requests'],
                'chats' => (int) $summary['unique_chats'],
                'users' => (int) $summary['unique_users'],
                'accounts' => (int) $summary['unique_accounts'],
            ],
            $this->TelegramReports->adminSummaries($Now)
        );

        $selected_day = null;
        $selected = trim((string) ($_GET['day'] ?? ''));
        if ($selected !== '') {
            try {
                TelegramUsageStore::assertDay($selected);
            } catch (\InvalidArgumentException) {
                SimpleRouter::response()->httpCode(400);
                return 'Invalid UTC day';
            }
            $oldest = $Now->setTime(0, 0)
                ->modify('-' . (TelegramUsageStore::RAW_RETENTION_DAYS - 1) . ' days')
                ->format('Y-m-d');
            if ($selected < $oldest || $selected > $Now->format('Y-m-d')) {
                SimpleRouter::response()->httpCode(400);
                return 'UTC day is outside the detailed retention window';
            }

            $details = $this->TelegramReports->adminDayDetails($selected, $Now);
            $aggregate = $details['aggregate'];
            $totals = is_array($aggregate['totals'] ?? null) ? $aggregate['totals'] : [];
            $selected_day = [
                'day' => $selected,
                'label' => DateTimeImmutable::createFromFormat('!Y-m-d', $selected, new DateTimeZone('UTC'))
                    ?->format('d.m.Y') ?? $selected,
                'requests' => (int) ($totals['requests'] ?? 0),
                'accounts' => array_map(fn(array $account): array => [
                    'id' => (string) ($account['account_id'] ?? ''),
                    'short_id' => $this->shortStellarId((string) ($account['account_id'] ?? '')),
                    'known' => ($account['known'] ?? false) === true,
                    'requests' => (int) ($account['requests'] ?? 0),
                    'chats' => (int) ($account['chat_count'] ?? 0),
                    'users' => (int) ($account['user_count'] ?? 0),
                ], is_array($aggregate['accounts'] ?? null) ? $aggregate['accounts'] : []),
                'chats' => array_map(static fn(array $chat): array => [
                    'id' => (string) ($chat['chat_id'] ?? ''),
                    'title' => is_string($chat['title'] ?? null) ? $chat['title'] : null,
                    'username' => is_string($chat['username'] ?? null) ? $chat['username'] : null,
                    'type' => (string) ($chat['type'] ?? ''),
                    'requests' => (int) ($chat['requests'] ?? 0),
                    'users' => (int) ($chat['user_count'] ?? 0),
                ], is_array($aggregate['chats'] ?? null) ? $aggregate['chats'] : []),
                'users' => array_map(static fn(array $user): array => [
                    'id' => (string) ($user['user_id'] ?? ''),
                    'name' => is_string($user['name'] ?? null) ? $user['name'] : null,
                    'username' => is_string($user['username'] ?? null) ? $user['username'] : null,
                    'requests' => (int) ($user['requests'] ?? 0),
                    'chats' => (int) ($user['chat_count'] ?? 0),
                ], is_array($aggregate['users'] ?? null) ? $aggregate['users'] : []),
            ];
        }

        return $this->Twig->render('admin_telegram.twig', [
            'config' => [
                'username' => $this->TelegramConfig->botUsername(),
                'bot_configured' => $this->TelegramBotApi->isConfigured(),
                'webhook_url' => $this->TelegramConfig->webhookUrl(),
                'secret_configured' => $this->TelegramConfig->hasValidWebhookSecret(),
                'admins_count' => count($this->TelegramConfig->adminIdsFailClosed()),
                'can_register_webhook' => $configuration_error === null,
                'error' => $configuration_error,
            ],
            'webhook' => $webhook,
            'days' => $days,
            'selected_day' => $selected_day,
            'notice' => $notice,
            'csrf_token' => $csrf_token,
        ]);
    }

    private function isAdmin(): bool
    {
        $account_id = $this->CurrentUser->getAccountId();
        if ($account_id === null) {
            return false;
        }

        $admins = preg_split('/[\s,;]+/', (string) ($_ENV['ADMINS'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $admins = array_map(static fn(string $item): string => strtoupper(trim($item)), $admins);

        return in_array(strtoupper($account_id), $admins, true);
    }

    private function csrfToken(string $purpose = 'admin_tomls'): string
    {
        return $this->RequestSession->getOrCreateToken('csrf:' . $purpose);
    }

    private function shortStellarId(string $account_id): string
    {
        return strlen($account_id) > 14
            ? substr($account_id, 0, 7) . '…' . substr($account_id, -7)
            : $account_id;
    }

    /** @return list<array<string, mixed>> */
    private function gristCacheRows(): array
    {
        $metadata = [
            GristSyncService::KNOWN_TOKENS => [
                'title' => 'Известные токены',
                'source' => 'Grist · Assets',
                'description' => 'Список токенов для каталога и распознавания активов. При обновлении перечитывается вся таблица; временные токены дополняются из текущего снимка BSN.',
            ],
            GristSyncService::MTLA_MEMBERS => [
                'title' => 'Участники MTLA',
                'source' => 'Grist · Users',
                'description' => 'Связи Stellar-аккаунтов участников с Telegram. Новый снимок применяется веб-процессами автоматически.',
            ],
            GristSyncService::DOCUMENTS => [
                'title' => 'Документы',
                'source' => 'Grist · Hashes',
                'description' => 'Реестр документов и их хешей. Записи source=grist, которых больше нет в таблице, удаляются.',
            ],
        ];
        $documents_count = count($this->DocumentsManager->getDocuments('grist'));
        $rows = [];

        foreach (GristSyncService::scopes() as $scope) {
            $job = $this->GristSyncJobs->status($scope);
            $snapshot = $scope === GristSyncService::DOCUMENTS
                ? null
                : $this->GristSnapshots->fetch($scope);
            $updated_at_ts = $snapshot['updated_at_ts'] ?? $job['last_success_at_ts'];
            $count = $scope === GristSyncService::DOCUMENTS
                ? $documents_count
                : ($snapshot === null ? null : count($snapshot['data']));

            $rows[] = array_merge($metadata[$scope], [
                'scope' => $scope,
                'count' => $count,
                'version' => $snapshot['version'] ?? null,
                'updated_at' => $updated_at_ts === null ? null : gmdate('d.m.Y H:i:s \\U\\T\\C', $updated_at_ts),
                'job' => $job,
                'state_label' => $this->gristJobStateLabel($job['state']),
                'state_class' => $this->gristJobStateClass($job['state']),
                'due_at' => $job['due_at_ts'] === null ? null : gmdate('d.m.Y H:i:s \\U\\T\\C', $job['due_at_ts']),
                'retry_after' => $job['retry_after_ts'] === null ? null : gmdate('d.m.Y H:i:s \\U\\T\\C', $job['retry_after_ts']),
            ]);
        }

        return $rows;
    }

    private function gristJobStateLabel(string $state): string
    {
        return match ($state) {
            'running' => 'обновляется',
            'scheduled' => 'запланировано',
            'pending' => 'ожидает запуска',
            'retry' => 'ожидает повтора',
            default => 'готово',
        };
    }

    private function gristJobStateClass(string $state): string
    {
        return match ($state) {
            'running' => 'is-info',
            'scheduled', 'pending' => 'is-warning',
            'retry' => 'is-danger',
            default => 'is-success is-light',
        };
    }

    private function formatGristSyncResult(string $scope, array $result): string
    {
        $titles = [
            GristSyncService::KNOWN_TOKENS => 'Известные токены',
            GristSyncService::MTLA_MEMBERS => 'Участники MTLA',
            GristSyncService::DOCUMENTS => 'Документы',
        ];
        $text = sprintf(
            '%s обновлены: %d записей',
            $titles[$scope],
            (int) ($result['count'] ?? 0)
        );
        if (($result['deleted'] ?? 0) > 0) {
            $text .= sprintf(', удалено устаревших: %d', (int) $result['deleted']);
        }

        return $text;
    }

    private function formatRefreshResult(array $result): string
    {
        $home_domain = (string) ($result['home_domain'] ?? '');
        if (($result['status'] ?? null) === 'ok') {
            $image_summary = $result['image_summary'] ?? [];
            $image_text = '';
            if (($image_summary['tasks'] ?? 0) > 0) {
                $image_text = sprintf(
                    ', изображения: %d ok, %d скачано',
                    (int) ($image_summary['ok'] ?? 0),
                    (int) ($image_summary['downloaded'] ?? 0)
                );
            }

            return sprintf(
                '%s обновлен%s%s',
                $home_domain ?: 'Домен',
                ($result['unchanged'] ?? false) ? ' без изменений' : '',
                $image_text
            );
        }
        if (($result['status'] ?? null) === 'ignored') {
            return sprintf('%s в игноре', $home_domain ?: 'Домен');
        }

        $error = $result['error'] ?? [];
        return trim(sprintf(
            '%s: %s',
            (string) ($error['code'] ?? 'error'),
            (string) ($error['message'] ?? 'Не удалось обновить')
        ));
    }
}
