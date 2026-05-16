<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
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

    private function csrfToken(): string
    {
        return hash('sha256', session_id() . ':admin_tomls');
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
