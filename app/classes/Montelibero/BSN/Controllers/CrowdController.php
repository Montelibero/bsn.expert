<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\CrowdProjectService;
use Montelibero\BSN\MarkdownRenderer;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class CrowdController implements RefreshDataCodeInterface
{
    use RefreshDataCodeTrait;

    private const REFRESH_SCOPE = 'crowd_snapshot';

    public function __construct(
        private readonly Environment $Twig,
        private readonly CrowdProjectService $ProjectService,
        private readonly CurrentUser $CurrentUser,
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
            'can_create' => $this->ProjectService->canCreateProjects($this->CurrentUser->getCurrentAccountId()),
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'is_wide_page' => true,
        ]);
    }

    public function Create(): ?string
    {
        if (!$this->ProjectService->canCreateProjects($this->CurrentUser->getCurrentAccountId())) {
            SimpleRouter::response()->httpCode(403);
            return $this->Twig->render('404.twig');
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

        return $this->Twig->render('crowd_create.twig', [
            'values' => $result['values'],
            'errors' => $result['errors'],
            'upload' => $result['upload'],
            'signing_form' => $signing_form,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'edit_project' => $edit_project,
            'crowd_token' => $this->ProjectService->crowdToken(),
            'is_wide_page' => true,
        ]);
    }

    public function Action(string $code, string $action): ?string
    {
        if (!$this->ProjectService->canCreateProjects($this->CurrentUser->getCurrentAccountId())) {
            SimpleRouter::response()->httpCode(403);
            return $this->Twig->render('404.twig');
        }

        try {
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
                'is_wide_page' => true,
            ]);
        } catch (\Throwable $Exception) {
            SimpleRouter::response()->httpCode(400);
            return $this->Twig->render('crowd_action.twig', [
                'project' => $this->ProjectService->findProject($code),
                'action' => $action,
                'error' => $Exception->getMessage(),
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

        return $this->Twig->render('crowd_project.twig', [
            'project' => $project,
            'snapshot' => $this->ProjectService->fetchSnapshot(),
            'refresh' => $refresh,
            'can_manage' => $this->ProjectService->canCreateProjects($this->CurrentUser->getCurrentAccountId()),
            'admin_actions' => $this->ProjectService->projectAdminActions($project, $this->CurrentUser->getCurrentAccountRequestParam()),
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
}
