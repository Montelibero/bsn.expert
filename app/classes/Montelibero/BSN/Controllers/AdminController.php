<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\GristSnapshotStore;
use Montelibero\BSN\GristSyncJobManager;
use Montelibero\BSN\GristSyncService;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarTomlCrawler;
use Montelibero\BSN\StellarTomlManager;
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
