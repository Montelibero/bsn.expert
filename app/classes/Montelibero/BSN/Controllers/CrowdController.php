<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\CrowdProjectService;
use Parsedown;
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
            'is_wide_page' => true,
        ]);
    }

    public function Create(): ?string
    {
        if (!$this->ProjectService->canCreateProjects($this->CurrentUser->getCurrentAccountId())) {
            SimpleRouter::response()->httpCode(403);
            return $this->Twig->render('404.twig');
        }

        $result = [
            'values' => $this->ProjectService->defaultCreateValues(),
            'errors' => [],
            'signing_xdr' => null,
            'signing_description' => null,
            'upload' => null,
        ];
        $signing_form = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = $this->ProjectService->prepareCreateProject($_POST);
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
            'is_wide_page' => true,
        ]);
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

        return $this->Twig->render('crowd_project.twig', [
            'project' => $project,
            'snapshot' => $this->ProjectService->fetchSnapshot(),
            'refresh' => $refresh,
            'is_wide_page' => true,
        ]);
    }

    private function renderMarkdown(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $Parsedown = new Parsedown();
        return $Parsedown->text($text);
    }
}
