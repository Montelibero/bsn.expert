<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\CrowdAccess;
use Montelibero\BSN\CrowdProjectService;
use Montelibero\BSN\MarkdownRenderer;
use Montelibero\BSN\RequestSession;
use Pecee\SimpleRouter\SimpleRouter;
use Throwable;
use Twig\Environment;

class CrowdController implements RefreshDataCodeInterface
{
    use RefreshDataCodeTrait;

    private const REFRESH_SCOPE = 'crowd_snapshot';
    private const CSRF_SESSION_KEY = 'crowd_csrf_token';

    public function __construct(
        private readonly Environment $Twig,
        private readonly CrowdProjectService $ProjectService,
        private readonly CrowdAccess $CrowdAccess,
        private readonly CurrentUser $CurrentUser,
        private readonly RequestSession $RequestSession,
        private readonly SignController $SignController,
        private readonly MarkdownRenderer $MarkdownRenderer,
    ) {
    }

    public function Index(): ?string
    {
        $can_refresh = $this->CurrentUser->isAuthorized();
        $force_refresh = $can_refresh && $this->isRefreshDataRequested(self::REFRESH_SCOPE);
        $snapshot = $this->ProjectService->fetchSnapshot($force_refresh);

        if ($force_refresh) {
            SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                'refresh_status' => ($snapshot['warning'] ?? null) !== null ? 'fallback' : null,
            ]), 302);
            return null;
        }

        $refresh = $this->buildRefreshDataContext(self::REFRESH_SCOPE, $can_refresh);
        $refresh['status'] = (string) ($_GET['refresh_status'] ?? '');

        return $this->Twig->render('crowd_index.twig', [
            'snapshot' => $snapshot,
            'refresh' => $refresh,
            'can_create' => $this->canManageForDisplay(),
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'is_wide_page' => true,
        ]);
    }

    public function Create(): ?string
    {
        if (($AccessResponse = $this->guardManageAccess()) !== null) {
            return $AccessResponse;
        }

        $edit_project = null;
        $edit_code = strtoupper(trim((string) ($_GET['code'] ?? '')));
        if ($edit_code !== '') {
            $edit_project = $this->ProjectService->findProject($edit_code);
            if (!$edit_project) {
                SimpleRouter::response()->httpCode(404);
                return $this->Twig->render('404.twig');
            }
        }

        $result = [
            'values' => $edit_project
                ? $this->ProjectService->createValuesFromProject($edit_project)
                : $this->ProjectService->defaultCreateValues(),
            'errors' => [],
            'signing_xdr' => null,
            'signing_description' => null,
            'upload' => null,
        ];
        $signing_form = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
                SimpleRouter::response()->httpCode(400);
                $result['values'] = $this->ProjectService->createValuesFromInput($_POST);
                if ($edit_project) {
                    $result['values']['code'] = (string) ($edit_project['code'] ?? '');
                }
                $result['errors'][] = ['key' => 'crowd_create.errors.csrf_invalid', 'params' => []];
            } else {
                $result = $edit_project
                    ? $this->ProjectService->prepareEditProject($edit_project, $_POST)
                    : $this->ProjectService->prepareCreateProject($_POST);
                if ($result['signing_xdr']) {
                    $signing_form = $this->SignController->SignTransaction(
                        $result['signing_xdr'],
                        null,
                        $result['signing_description']
                    );
                }
            }
        }

        return $this->Twig->render('crowd_create.twig', [
            'values' => $result['values'],
            'errors' => $result['errors'],
            'upload' => $result['upload'],
            'signing_form' => $signing_form,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'edit_project' => $edit_project,
            'crowd_token' => $this->ProjectService->crowdToken(),
            'csrf_token' => $this->csrfToken(),
            'is_wide_page' => true,
        ]);
    }

    public function Action(string $code, string $action): ?string
    {
        if (($AccessResponse = $this->guardManageAccess()) !== null) {
            return $AccessResponse;
        }

        try {
            if (!$this->isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
                SimpleRouter::response()->httpCode(400);
                $context = $this->ProjectService->projectActionContext($code, $action);
                return $this->Twig->render('crowd_action.twig', $context + [
                    'error_key' => 'crowd_action.errors.csrf_invalid',
                    'csrf_token' => $this->csrfToken(),
                    'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
                    'is_wide_page' => true,
                ]);
            }

            $result = $this->ProjectService->prepareProjectAction($code, $action);
            $signing_form = $this->SignController->SignTransaction(
                $result['signing_xdr'],
                null,
                $result['signing_description']
            );

            return $this->Twig->render('crowd_action.twig', [
                'project' => $result['project'],
                'action' => $result['action'],
                'upload' => $result['upload'],
                'signing_form' => $signing_form,
                'csrf_token' => $this->csrfToken(),
                'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
                'is_wide_page' => true,
            ]);
        } catch (\Throwable $Exception) {
            SimpleRouter::response()->httpCode(400);
            return $this->Twig->render('crowd_action.twig', [
                'project' => $this->ProjectService->findProject($code),
                'action' => $action,
                'error' => $Exception->getMessage(),
                'csrf_token' => $this->csrfToken(),
                'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
                'is_wide_page' => true,
            ]);
        }
    }

    public function Project(string $code): ?string
    {
        $can_refresh = $this->CurrentUser->isAuthorized();
        $force_refresh = $can_refresh && $this->isRefreshDataRequested(self::REFRESH_SCOPE);
        $project = $this->ProjectService->findProject($code, $force_refresh);
        if (!$project) {
            SimpleRouter::response()->httpCode(404);
            return $this->Twig->render('404.twig');
        }

        if ($force_refresh) {
            SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                'refresh_status' => ($project['warning'] ?? null) !== null ? 'fallback' : null,
            ]), 302);
            return null;
        }

        $refresh = $this->buildRefreshDataContext(self::REFRESH_SCOPE, $can_refresh);
        $refresh['status'] = (string) ($_GET['refresh_status'] ?? '');
        $project['full_description_html'] = $this->renderMarkdown($project['full_description'] ?? '');
        $donation = null;
        $donation_signing_form = null;
        $donation_amount = trim((string) ($_GET['amount'] ?? ''));
        if ($donation_amount !== '') {
            $current_account_id = $this->CurrentUser->getCurrentAccountId();
            if ($current_account_id === null) {
                SimpleRouter::response()->redirect('/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? CrowdProjectService::BASE_PATH . '/' . rawurlencode($project['code'])), 302);
                return null;
            }

            $donation = $this->ProjectService->prepareDonation($project['code'], $current_account_id, $donation_amount);
            if ($donation['signing_xdr']) {
                $donation_signing_form = $this->SignController->SignTransaction(
                    $donation['signing_xdr'],
                    null,
                    $donation['signing_description']
                );
            }
        }

        $can_manage = $this->canManageForDisplay();

        return $this->Twig->render('crowd_project.twig', [
            'project' => $project,
            'snapshot' => $this->ProjectService->fetchSnapshot(),
            'refresh' => $refresh,
            'can_manage' => $can_manage,
            'admin_actions' => $this->ProjectService->projectAdminActions($project, $this->CurrentUser->getCurrentAccountRequestParam()),
            'csrf_token' => $can_manage ? $this->csrfToken() : null,
            'donation' => $donation,
            'donation_signing_form' => $donation_signing_form,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'is_wide_page' => true,
        ]);
    }

    private function renderMarkdown(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return $this->MarkdownRenderer->render($text);
    }

    private function canManageForDisplay(): bool
    {
        try {
            return $this->CrowdAccess->canManage();
        } catch (Throwable $Exception) {
            error_log('Unable to check crowd access: ' . $Exception->getMessage());
            return false;
        }
    }

    private function guardManageAccess(): ?string
    {
        try {
            $can_manage = $this->CrowdAccess->canManage();
        } catch (Throwable $Exception) {
            error_log('Unable to check crowd access: ' . $Exception->getMessage());
            SimpleRouter::response()->httpCode(503);
            return 'Stellar node error';
        }

        if ($can_manage) {
            return null;
        }

        SimpleRouter::response()->httpCode(403);
        return $this->Twig->render('404.twig');
    }

    private function csrfToken(): string
    {
        $token = $this->RequestSession->get(self::CSRF_SESSION_KEY);
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $this->RequestSession->set(self::CSRF_SESSION_KEY, $token);
        }

        return $token;
    }

    private function isCsrfTokenValid(mixed $token): bool
    {
        return is_string($token) && hash_equals($this->csrfToken(), $token);
    }
}
