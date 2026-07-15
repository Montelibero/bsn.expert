<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\EurmtlReport2Config;
use Montelibero\BSN\EurmtlReport2Service;
use Montelibero\BSN\EurmtlReportAccess;
use Montelibero\BSN\RequestSession;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class EurmtlReport2Controller implements RefreshDataCodeInterface
{
    use RefreshDataCodeTrait;

    private const REFRESH_SCOPE = 'eurmtl_report2_snapshot';

    public function __construct(
        private readonly Environment $Twig,
        private readonly EurmtlReport2Service $ReportService,
        private readonly EurmtlReportAccess $ReportAccess,
        private readonly RequestSession $RequestSession,
    ) {
    }

    public function EurmtlReport2(): ?string
    {
        if (!$this->ReportAccess->isAuthenticated()) {
            SimpleRouter::response()->redirect(LoginController::getLoginUrlForCurrentRequest('/tools/eurmtl_report2'), 302);
            return null;
        }

        if (!$this->ReportAccess->canView(EurmtlReport2Config::ISSUER)) {
            SimpleRouter::response()->httpCode(403);
            return null;
        }

        $can_refresh = $this->ReportService->canRefreshSnapshot();
        $force_refresh = $can_refresh && $this->isRefreshDataRequested(self::REFRESH_SCOPE);
        $snapshot = $this->ReportService->fetchSnapshot($force_refresh);

        if ($force_refresh) {
            SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                'refresh_status' => ($snapshot['warning'] ?? null) !== null ? 'fallback' : null,
            ]), 302);
            return null;
        }

        $refresh = $this->buildRefreshDataContext(self::REFRESH_SCOPE, $can_refresh);
        $refresh['status'] = (string) ($_GET['refresh_status'] ?? '');

        return $this->Twig->render('tools_eurmtl_report2.twig', [
            'is_wide_page' => true,
            'snapshot' => $snapshot,
            'refresh' => $refresh,
        ]);
    }
}
