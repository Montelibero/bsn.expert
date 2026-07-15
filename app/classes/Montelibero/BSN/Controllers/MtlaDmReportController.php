<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\MTLA\MtlaProgramReportService;
use Montelibero\BSN\RequestSession;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;
use function htmlspecialchars;

class MtlaDmReportController implements RefreshDataCodeInterface
{
    use RefreshDataCodeTrait;

    private const REFRESH_SCOPE = 'mtla_dm_report_snapshot';

    private BSN $BSN;
    private Environment $Twig;
    private CurrentUser $CurrentUser;
    private MtlaProgramReportService $ReportService;
    private RequestSession $RequestSession;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        CurrentUser $CurrentUser,
        MtlaProgramReportService $ReportService,
        RequestSession $RequestSession,
    )
    {
        $this->BSN = $BSN;
        $this->Twig = $Twig;
        $this->CurrentUser = $CurrentUser;
        $this->ReportService = $ReportService;
        $this->RequestSession = $RequestSession;
    }

    public function MtlaDmReport(): ?string
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
        $programs['items'] = $this->attachProgramTurnover($programs['items'], $snapshot);
        $programs['problem_items'] = array_values(array_filter(
            $programs['items'],
            static fn(array $item): bool => $item['has_issues']
        ));
        $activists = $this->ReportService->collectActivists($programs['memberships'], $snapshot);

        if ($force_refresh) {
            SimpleRouter::response()->redirect($this->getRefreshDataRedirectUri([
                'refresh_status' => ($snapshot['warning'] ?? null) !== null ? 'fallback' : null,
            ]), 302);
            return null;
        }

        $heroes = array_values(array_filter($activists, static fn(array $item): bool => $item['is_ideal']));
        $anomalies = [
            'missing_timetoken' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_timetoken'])),
            'missing_trustline' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_trustline'])),
            'not_sending_tokens' => array_values(array_filter($activists, static fn(array $item): bool => $item['low_incoming'])),
            'tokens_not_spent' => array_values(array_filter($activists, static fn(array $item): bool => $item['low_outgoing'])),
            'without_programs' => array_values(array_filter($activists, static fn(array $item): bool => $item['missing_programs'])),
        ];
        $refresh = $this->buildRefreshDataContext(self::REFRESH_SCOPE, $can_refresh);
        $refresh['status'] = (string) ($_GET['refresh_status'] ?? '');
        $can_copy_clipboard = $this->CurrentUser->getMemberLevel() >= 4;

        $Template = $this->Twig->load('mtla_dm_report.twig');
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
            'anomalies' => $anomalies,
            'can_copy_clipboard' => $can_copy_clipboard,
            'clipboard_groups' => $can_copy_clipboard ? [
                'heroes' => $this->buildClipboardPayload($heroes),
                'missing_timetoken' => $this->buildClipboardPayload($anomalies['missing_timetoken']),
                'missing_trustline' => $this->buildClipboardPayload($anomalies['missing_trustline']),
                'not_sending_tokens' => $this->buildClipboardPayload($anomalies['not_sending_tokens']),
                'tokens_not_spent' => $this->buildClipboardPayload($anomalies['tokens_not_spent']),
                'without_programs' => $this->buildClipboardPayload($anomalies['without_programs']),
            ] : [],
            'programs' => $programs['items'],
            'problem_programs' => $programs['problem_items'],
        ]);
    }

    private function attachProgramTurnover(array $programs, array $snapshot): array
    {
        foreach ($programs as &$program) {
            $program_id = $program['data']['id'] ?? null;
            $program_outgoing = is_string($program_id)
                ? (array) ($snapshot['program_outgoing_totals'][$program_id] ?? [])
                : [];

            $turnover_total = 0.0;
            $turnover_participants = 0;

            foreach ($program['participants'] as $participant) {
                $asset_key = null;
                if (!empty($participant['tt_code']) && !empty($participant['tt_issuer'])) {
                    $asset_key = $participant['tt_code'] . '-' . $participant['tt_issuer'];
                }

                if ($asset_key === null) {
                    continue;
                }

                $amount = (float) ($program_outgoing[$asset_key] ?? 0.0);
                if ($amount <= 0.0) {
                    continue;
                }

                $turnover_total += $amount;
                $turnover_participants++;
            }

            $program['turnover_total'] = $turnover_total;
            $program['turnover_participants'] = $turnover_participants;
        }
        unset($program);

        return $programs;
    }

    private function buildClipboardPayload(array $items): ?array
    {
        $html_items = [];
        $text_items = [];
        $base_url = $this->resolveBaseUrl();

        foreach ($items as $item) {
            $account_id = $item['account']['id'] ?? null;
            if (!$account_id) {
                continue;
            }

            $Account = $this->BSN->getAccountById($account_id);
            if ($Account === null) {
                continue;
            }

            $username = $item['account']['username'] ?? null;
            $telegram_username = $Account->getTelegramUsername();
            $account_path = $username ? '/@' . $username : '/accounts/' . $account_id;
            $account_url = $base_url . $account_path;
            $account_link_text = $Account->getShortId();
            $telegram_label = $telegram_username ? '@' . $telegram_username . ' ' : '';

            $html_items[] = sprintf(
                '<li>%s<a href="%s">%s</a></li>',
                htmlspecialchars($telegram_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($account_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($account_link_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );

            $text_items[] = trim($telegram_label . $account_link_text . ' ' . $account_url);
        }

        if (!$html_items || !$text_items) {
            return null;
        }

        return [
            'html' => '<ul>' . implode('', $html_items) . '</ul>',
            'text' => implode("\n", $text_items),
        ];
    }

    private function resolveBaseUrl(): string
    {
        $scheme = 'http';

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = (string) $_SERVER['HTTP_X_FORWARDED_PROTO'];
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = (string) $_SERVER['REQUEST_SCHEME'];
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host;
    }
}
