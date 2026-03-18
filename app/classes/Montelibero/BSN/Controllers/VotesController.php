<?php

namespace Montelibero\BSN\Controllers;

use DateTimeImmutable;
use Exception;
use Montelibero\BSN\BSN;
use RuntimeException;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class VotesController
{
    private const CACHE_TTL = 86400;
    private const SHEET_CSV_URL = 'https://docs.google.com/spreadsheets/d/%s/gviz/tq?tqx=out:csv&sheet=%s';
    private const SPREADSHEET_URL = 'https://docs.google.com/spreadsheets/d/%s';

    private BSN $BSN;
    private Environment $Twig;
    private Translator $Translator;

    public function __construct(BSN $BSN, Environment $Twig, Translator $Translator)
    {
        $this->BSN = $BSN;
        $this->Twig = $Twig;
        $this->Translator = $Translator;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function MtlaVotes(): string
    {
        $links_input = '';
        $error = '';
        $summary = null;
        $spreadsheet_ids = $this->extractSpreadsheetIdsFromGet($_GET['d'] ?? []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $links_input = trim((string) ($_POST['links'] ?? ''));
            try {
                $spreadsheet_ids = $this->extractSpreadsheetIds($links_input);
                if (!$spreadsheet_ids) {
                    throw new RuntimeException($this->Translator->trans('mtla_votes_page.errors.no_public_links'));
                }

                $this->redirectToDocuments($spreadsheet_ids);
            } catch (Exception $E) {
                $error = $E->getMessage();
            }
        }

        if ($spreadsheet_ids) {
            $links_input = $this->buildLinksInput($spreadsheet_ids);

            try {
                $documents = [];
                foreach ($spreadsheet_ids as $spreadsheet_id) {
                    $documents[] = $this->fetchVotingDocument($spreadsheet_id);
                }

                usort($documents, function (array $left, array $right): int {
                    $left_ts = $left['date_sort_ts'] ?? PHP_INT_MAX;
                    $right_ts = $right['date_sort_ts'] ?? PHP_INT_MAX;

                    if ($left_ts === $right_ts) {
                        return strcmp($left['spreadsheet_id'], $right['spreadsheet_id']);
                    }

                    // "От старшего к младшему": сначала более ранние голосования.
                    return $left_ts <=> $right_ts;
                });

                foreach ($documents as $index => &$document) {
                    $document['index'] = $index + 1;
                }
                unset($document);

                $summary = $this->buildSummary($documents);
            } catch (Exception $E) {
                $error = $E->getMessage();
            }
        }

        return $this->Twig->render('tools_mtla_votes.twig', [
            'links' => $links_input,
            'error' => $error,
            'summary' => $summary,
            'is_wide_page' => true,
        ]);
    }

    private function extractSpreadsheetIds(string $input): array
    {
        $ids = [];
        $tokens = preg_split('/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $spreadsheet_id = null;
            if (preg_match('~docs\.google\.com/spreadsheets/d/([a-zA-Z0-9\-_]+)~', $token, $m)) {
                $spreadsheet_id = $m[1];
            } elseif (preg_match('/\A[a-zA-Z0-9\-_]{20,}\z/', $token)) {
                $spreadsheet_id = $token;
            }

            if ($spreadsheet_id) {
                $ids[$spreadsheet_id] = true;
            }
        }

        return array_keys($ids);
    }

    private function extractSpreadsheetIdsFromGet(mixed $value): array
    {
        $ids = [];
        $items = is_array($value) ? $value : [$value];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '' || !preg_match('/\A[a-zA-Z0-9\-_]{20,}\z/', $item)) {
                continue;
            }

            $ids[$item] = true;
        }

        return array_keys($ids);
    }

    private function buildLinksInput(array $spreadsheet_ids): string
    {
        $links = array_map(fn(string $id): string => sprintf(self::SPREADSHEET_URL, $id), $spreadsheet_ids);

        return implode("\n", $links);
    }

    private function redirectToDocuments(array $spreadsheet_ids): never
    {
        $query = http_build_query(['d' => array_values($spreadsheet_ids)]);
        $url = '/tools/mtla/votes' . ($query ? '?' . $query : '');

        header('Location: ' . $url, true, 303);
        exit;
    }

    private function fetchVotingDocument(string $spreadsheet_id): array
    {
        $cache_key = 'mtla_votes_doc:' . sha1($spreadsheet_id) . ':v4';
        if (($cached = $this->cacheFetch($cache_key)) !== null) {
            return $cached;
        }

        $result_rows = $this->downloadSheetRows($spreadsheet_id, 'Result');
        $log_rows = $this->downloadSheetRows($spreadsheet_id, 'Log');
        $members_rows = $this->downloadSheetRows($spreadsheet_id, 'Members');

        ['title' => $title, 'date_started' => $date_started] = $this->extractResultMeta($result_rows);

        $members = [];
        foreach ($members_rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $account_id = trim((string) ($row['A'] ?? ''));
            if (!BSN::validateStellarAccountIdFormat($account_id)) {
                continue;
            }

            $members[$account_id] = [
                'account_id' => $account_id,
                'delegated_to' => trim((string) ($row['B'] ?? '')),
            ];
        }

        $statuses = [];
        foreach ($log_rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $account_id = trim((string) ($row['A'] ?? ''));
            if (!BSN::validateStellarAccountIdFormat($account_id)) {
                continue;
            }

            $positive = trim((string) ($row['C'] ?? ''));
            $negative = trim((string) ($row['D'] ?? ''));
            $marker = $positive !== '' ? $positive : $negative;
            if ($marker === '') {
                continue;
            }

            $status = strtoupper($marker) === 'X' ? 'direct' : 'delegated';
            if (!isset($statuses[$account_id]) || $status === 'direct') {
                $statuses[$account_id] = $status;
            }
        }

        foreach ($members as $account_id => $_item) {
            $statuses[$account_id] = $statuses[$account_id] ?? 'absent';
        }

        $document = [
            'spreadsheet_id' => $spreadsheet_id,
            'url' => sprintf(self::SPREADSHEET_URL, $spreadsheet_id),
            'title' => $title,
            'title_display' => $this->extractShortTitle($title),
            'date_started' => $date_started,
            'date_label' => $this->extractDateLabel($date_started),
            'date_sort_ts' => $this->parseDateToTimestamp($date_started),
            'members' => $members,
            'statuses' => $statuses,
        ];

        $this->cacheStore($cache_key, $document, self::CACHE_TTL);

        return $document;
    }

    private function buildSummary(array $documents): array
    {
        $all_member_ids = [];
        foreach ($documents as $document) {
            foreach (array_keys($document['members']) as $account_id) {
                $all_member_ids[$account_id] = true;
            }
        }

        $rows = [];
        foreach (array_keys($all_member_ids) as $account_id) {
            $Account = $this->BSN->makeAccountById($account_id);

            $statuses = [];
            $ever_voted = false;
            $member_everywhere = true;
            $voted_direct_everywhere = true;

            foreach ($documents as $document) {
                if (!isset($document['members'][$account_id])) {
                    $status = 'not_member';
                    $member_everywhere = false;
                    $voted_direct_everywhere = false;
                } else {
                    $status = $document['statuses'][$account_id] ?? 'absent';
                    if ($status !== 'direct') {
                        $voted_direct_everywhere = false;
                    }
                    if ($status === 'direct' || $status === 'delegated') {
                        $ever_voted = true;
                    }
                }

                $statuses[] = [
                    'spreadsheet_id' => $document['spreadsheet_id'],
                    'status' => $status,
                ];
            }

            $rows[] = [
                'account' => $Account->jsonSerialize(),
                'sort_key' => $this->normalizeSortKey($Account->getDisplayName()),
                'statuses' => $statuses,
                'never_voted' => $member_everywhere && !$ever_voted,
                'voted_direct_everywhere' => $voted_direct_everywhere,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            if ($left['sort_key'] === $right['sort_key']) {
                return strcmp($left['account']['id'], $right['account']['id']);
            }

            return $left['sort_key'] <=> $right['sort_key'];
        });

        $never_voted = [];
        $heroes = [];
        foreach ($rows as $row) {
            if ($row['never_voted']) {
                $never_voted[] = $row['account'];
            }
            if ($row['voted_direct_everywhere']) {
                $heroes[] = $row['account'];
            }
        }

        return [
            'documents' => $documents,
            'rows' => $rows,
            'never_voted' => $never_voted,
            'heroes' => $heroes,
        ];
    }

    private function downloadSheetRows(string $spreadsheet_id, string $sheet_name): array
    {
        $response = $this->downloadUrl(
            sprintf(self::SHEET_CSV_URL, rawurlencode($spreadsheet_id), rawurlencode($sheet_name))
        );

        $rows = $this->parseCsvRows($response);
        if (!$rows) {
            throw new RuntimeException($this->Translator->trans('mtla_votes_page.errors.sheet_missing', [
                '%sheet%' => $sheet_name,
                '%id%' => $spreadsheet_id,
            ]));
        }

        return $rows;
    }

    private function downloadUrl(string $url): string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: BSN MTLA votes tool',
            ],
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if (!is_string($response) || $response === '') {
            throw new RuntimeException(
                $this->Translator->trans('mtla_votes_page.errors.download_failed', [
                    '%suffix%' => $curl_error ? ': ' . $curl_error : '.',
                ])
            );
        }

        if ($http_code >= 400) {
            throw new RuntimeException($this->Translator->trans('mtla_votes_page.errors.http_code', [
                '%code%' => $http_code,
            ]));
        }

        return $response;
    }

    private function parseCsvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Не удалось создать временный буфер для разбора CSV.');
        }

        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($columns = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($columns === [null]) {
                continue;
            }

            $row = [];
            foreach ($columns as $index => $value) {
                $row[$this->columnLetter($index)] = trim((string) $value);
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function extractResultMeta(array $rows): array
    {
        $result_map = $this->buildKeyValueMap($rows);
        $title = trim((string) ($result_map['Вопрос'] ?? ''));
        $date_started = trim((string) ($result_map['Дата начала'] ?? ''));

        if ($title !== '' || $date_started !== '') {
            return [
                'title' => $title,
                'date_started' => $date_started,
            ];
        }

        $title = trim((string) ($rows[0]['B'] ?? ''));
        $date_started = '';
        if ($title !== '' && preg_match('/(?<date>\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2})\s*$/u', $title, $m, PREG_OFFSET_CAPTURE)) {
            $date_started = $m['date'][0];
            $date_pos = $m['date'][1];
            $title = trim(substr($title, 0, $date_pos));
        }

        return [
            'title' => $title,
            'date_started' => $date_started,
        ];
    }

    private function columnLetter(int $index): string
    {
        $result = '';
        $number = $index + 1;
        while ($number > 0) {
            $number--;
            $result = chr(65 + ($number % 26)) . $result;
            $number = intdiv($number, 26);
        }

        return $result;
    }

    private function buildKeyValueMap(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['A'] ?? ''));
            if ($key === '') {
                continue;
            }
            $result[$key] = trim((string) ($row['B'] ?? ''));
        }

        return $result;
    }

    private function parseDateToTimestamp(string $value): int
    {
        if ($value === '') {
            return PHP_INT_MAX;
        }

        $Date = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $value);
        if ($Date instanceof DateTimeImmutable) {
            return $Date->getTimestamp();
        }

        return PHP_INT_MAX;
    }

    private function extractDateLabel(string $value): string
    {
        if ($value === '') {
            return $this->Translator->trans('mtla_votes_page.no_date');
        }

        $Date = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $value);
        if ($Date instanceof DateTimeImmutable) {
            return $Date->format('d.m.Y');
        }

        return $value;
    }

    private function extractShortTitle(string $title): string
    {
        foreach (preg_split('/\R+/', $title) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            return preg_replace('/^(ru|en):\s*/iu', '', $line) ?: $line;
        }

        return $title;
    }

    private function cacheFetch(string $key): mixed
    {
        if (!function_exists('apcu_fetch')) {
            return null;
        }

        $success = false;
        $value = apcu_fetch($key, $success);

        return $success ? $value : null;
    }

    private function cacheStore(string $key, mixed $value, int $ttl): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }

        apcu_store($key, $value, $ttl);
    }

    private function normalizeSortKey(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }

        return strtolower($value);
    }
}
