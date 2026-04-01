<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\MTLA\MtlaProgramReportService;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class MtlaRpReportController implements RefreshDataCodeInterface
{
    use RefreshDataCodeTrait;

    private const REFRESH_SCOPE = 'mtla_rp_report_snapshot';

    private Environment $Twig;
    private MtlaProgramReportService $ReportService;

    public function __construct(Environment $Twig, MtlaProgramReportService $ReportService)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        $this->ReportService = $ReportService;
    }

    public function MtlaRpReport(): ?string
    {
        $can_refresh = $this->ReportService->canRefreshSnapshot();
        $force_refresh = $can_refresh && $this->isRefreshDataRequested(self::REFRESH_SCOPE);
        $programs = $this->ReportService->collectPrograms();
        $snapshot = $this->ReportService->fetchMtlaSnapshot(
            array_map(
                static fn(array $item): string => $item['data']['id'],
                $programs['items']
            ),
            $force_refresh
        );
        $activists = $this->ReportService->collectActivists($programs['memberships'], $snapshot);

        if ($force_refresh) {
            SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                'refresh_status' => ($snapshot['warning'] ?? null) !== null ? 'fallback' : null,
            ]), 302);
            return null;
        }

        $heroes = array_values(array_filter($activists, static fn(array $item): bool => $item['is_ideal']));
        $refresh = $this->buildRefreshDataContext(self::REFRESH_SCOPE, $can_refresh);
        $refresh['status'] = (string) ($_GET['refresh_status'] ?? '');

        $Template = $this->Twig->load('tools_mtla_rp_report.twig');
        return $Template->render([
            'is_wide_page' => true,
            'mtla_account' => $this->ReportService->getMtlaAccountData(),
            'summary' => [
                'activists_total' => count($activists),
                'heroes_total' => count($heroes),
                'programs_total' => count($programs['items']),
                'problem_programs_total' => count($programs['problem_items']),
            ],
            'snapshot' => $snapshot,
            'refresh' => $refresh,
            'activists' => $activists,
            'heroes' => $heroes,
            'anomalies' => [
                'missing_timetoken' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_timetoken'])),
                'missing_trustline' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_trustline'])),
                'not_sending_tokens' => array_values(array_filter($activists, static fn(array $item): bool => $item['low_incoming'])),
                'tokens_not_spent' => array_values(array_filter($activists, static fn(array $item): bool => $item['low_outgoing'])),
                'without_programs' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_programs'])),
            ],
            'programs' => $programs['items'],
            'problem_programs' => $programs['problem_items'],
        ]);
    }
}
