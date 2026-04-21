<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\DocumentsManager;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class SearchController
{
    private const HTML_RESULTS_LIMIT = 50;
    private const JSON_DEFAULT_LIMIT = 10;
    private const JSON_MAX_LIMIT = 50;
    private const MIN_LENGTH_TAGS = 1;
    private const MIN_LENGTH_TOKENS = 1;
    private const MIN_LENGTH_ACCOUNTS = 3;
    private const MIN_LENGTH_DOCUMENTS = 3;

    public function __construct(
        private readonly BSN $BSN,
        private readonly DocumentsManager $DocumentsManager,
        private readonly TokensController $TokensController,
        private readonly Environment $Twig,
        private readonly Translator $Translator,
        private readonly CurrentUser $CurrentUser,
    ) {
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function Search(): ?string
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $is_json_request = $this->isJsonRequest();
        $limit = $is_json_request ? $this->resolveJsonLimit() : self::HTML_RESULTS_LIMIT;

        if (!$is_json_request && ($redirect_url = $this->resolveDirectEntityRedirect($query))) {
            SimpleRouter::response()->redirect($redirect_url);
            return null;
        }

        $search_result = $this->performSearch($query, $limit);

        if ($is_json_request) {
            header('Content-Type: application/json; charset=utf-8');

            return json_encode(
                [
                    'query' => $query,
                    'results' => $search_result['results'],
                    'meta' => [
                        'total' => $search_result['total'],
                        'returned' => count($search_result['results']),
                        'limit' => $limit,
                        'has_more' => $search_result['total'] > count($search_result['results']),
                        'min_length' => [
                            'tags' => self::MIN_LENGTH_TAGS,
                            'tokens' => self::MIN_LENGTH_TOKENS,
                            'accounts' => self::MIN_LENGTH_ACCOUNTS,
                            'documents' => self::MIN_LENGTH_DOCUMENTS,
                        ],
                        'sources' => $search_result['sources'],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $Template = $this->Twig->load('search.twig');
        return $Template->render([
            'q' => $query,
            'results' => $search_result['results'],
            'results_total' => $search_result['total'],
            'results_returned' => count($search_result['results']),
            'results_has_more' => $search_result['total'] > count($search_result['results']),
        ]);
    }

    private function performSearch(string $query, int $limit): array
    {
        if ($query === '') {
            return [
                'results' => [],
                'total' => 0,
                'sources' => [],
            ];
        }

        $results = [];
        $sources = [];
        $query_length = mb_strlen($query);

        if ($query_length >= self::MIN_LENGTH_TAGS) {
            $sources[] = 'tags';
            $results = [...$results, ...$this->searchTags($query)];
        }

        if ($query_length >= self::MIN_LENGTH_TOKENS) {
            $sources[] = 'tokens';
            $results = [...$results, ...$this->searchTokens($query)];
        }

        if ($query_length >= self::MIN_LENGTH_ACCOUNTS) {
            $sources[] = 'accounts';
            $results = [...$results, ...$this->searchAccounts($query)];
        }

        if ($query_length >= self::MIN_LENGTH_DOCUMENTS) {
            $sources[] = 'documents';
            $results = [...$results, ...$this->searchDocuments($query)];
        }

        usort($results, function (array $a, array $b): int {
            return ($b['_score'] <=> $a['_score'])
                ?: (($b['exact_match'] ?? false) <=> ($a['exact_match'] ?? false))
                ?: strcmp($a['title_sort'], $b['title_sort'])
                ?: strcmp($a['url'], $b['url']);
        });

        $total = count($results);
        $results = array_slice($results, 0, $limit);
        $results = array_map(function (array $result): array {
            unset($result['_score'], $result['title_sort']);
            return $result;
        }, $results);

        return [
            'results' => $results,
            'total' => $total,
            'sources' => $sources,
        ];
    }

    private function searchTags(string $query): array
    {
        $results = [];

        foreach ($this->BSN->getTags() as $Tag) {
            $match = $this->matchText($query, $Tag->getName());
            if (!$match) {
                continue;
            }

            $results[] = $this->finalizeResult([
                'entity_type' => 'tag',
                'entity_type_label' => $this->Translator->trans('search.types.tag'),
                'entity_id' => $Tag->getName(),
                'url' => SimpleRouter::getUrl('tag', ['id' => $Tag->getName()]),
                'icon_class' => 'fa-solid fa-hashtag',
                'title' => $Tag->getName(),
                'title_parts' => $this->makeHighlightParts($Tag->getName(), $query),
                'subtitle' => null,
                'subtitle_parts' => [],
                'match_label' => $this->Translator->trans('search.fields.tag_name'),
                'match_field' => 'tag_name',
                'match_kind' => $match['kind'],
                'matched_value' => $Tag->getName(),
                'match_parts' => $this->makeHighlightParts($Tag->getName(), $query),
                'show_match_line' => false,
                'exact_match' => $match['exact'],
                '_score' => 80 + $match['score'],
                'title_sort' => mb_strtolower($Tag->getName()),
            ]);
        }

        return $results;
    }

    private function searchTokens(string $query): array
    {
        $results = [];

        foreach ($this->TokensController->getKnownTokens() as $token) {
            $match = $this->matchText($query, $token['code']);
            if (!$match) {
                continue;
            }

            $subtitle = $this->BSN->makeAccountById($token['issuer'])->getShortId();
            $results[] = $this->finalizeResult([
                'entity_type' => 'token',
                'entity_type_label' => $this->Translator->trans('search.types.token'),
                'entity_id' => $token['code'],
                'url' => SimpleRouter::getUrl('token_page', ['code' => $token['code']]),
                'icon_class' => 'fa-solid fa-certificate',
                'title' => $token['code'],
                'title_parts' => $this->makeHighlightParts($token['code'], $query),
                'subtitle' => $subtitle,
                'subtitle_parts' => [],
                'match_label' => $this->Translator->trans('search.fields.token_code'),
                'match_field' => 'token_code',
                'match_kind' => $match['kind'],
                'matched_value' => $token['code'],
                'match_parts' => $this->makeHighlightParts($token['code'], $query),
                'show_match_line' => false,
                'exact_match' => $match['exact'],
                '_score' => 100 + $match['score'],
                'title_sort' => mb_strtolower($token['code']),
            ]);
        }

        return $results;
    }

    private function searchAccounts(string $query): array
    {
        $results = [];
        $show_telegram = $this->CurrentUser->getShowTelegramUsernames();

        foreach ($this->BSN->getAccounts() as $Account) {
            $match_data = $this->matchAccount($Account, $query, $show_telegram);
            if (!$match_data) {
                continue;
            }

            $title = $this->resolveAccountTitle($Account);
            $subtitle = $this->resolveAccountSubtitle($Account, $title);

            $results[] = $this->finalizeResult([
                'entity_type' => 'account',
                'entity_type_label' => $this->Translator->trans('search.types.account'),
                'entity_id' => $Account->getId(),
                'url' => $this->resolveAccountUrl($Account),
                'icon_class' => $Account->getBalance('MTLAC') > 0 ? 'fa-regular fa-building' : 'fa-regular fa-circle-user',
                'title' => $title,
                'title_parts' => $this->makeHighlightParts($title, $query),
                'subtitle' => $subtitle,
                'subtitle_parts' => $subtitle ? $this->makeHighlightParts($subtitle, $query) : [],
                'match_label' => $match_data['label'],
                'match_field' => $match_data['field'],
                'match_kind' => $match_data['match']['kind'],
                'matched_value' => $match_data['display'],
                'match_parts' => $this->makeHighlightParts($match_data['display'], $query),
                'show_match_line' => $this->shouldShowMatchLine($match_data['display'], [$title, $subtitle]),
                'exact_match' => $match_data['match']['exact'],
                '_score' => 60 + $match_data['score'] + min(40, $Account->calcBsnScore()),
                'title_sort' => mb_strtolower($title),
            ]);
        }

        return $results;
    }

    private function searchDocuments(string $query): array
    {
        $results = [];
        $Contracts = $this->BSN->getSignatures();

        foreach ($Contracts->getContractsByUsing() as $hash => $using_count) {
            if ($using_count <= 0) {
                continue;
            }

            $Contract = $Contracts->makeContract($hash);
            $name = $Contract->getDisplayName();
            $best_match = null;

            if ($Contract->getName()) {
                $name_match = $this->matchText($query, $Contract->getName());
                if ($name_match) {
                    $best_match = [
                        'field' => 'document_name',
                        'label' => $this->Translator->trans('search.fields.document_name'),
                        'display' => $name,
                        'match' => $name_match,
                        'score' => 100 + $name_match['score'],
                    ];
                }
            }

            $hash_match = $this->matchText($query, $Contract->hash);
            if ($hash_match) {
                $hash_score = 70 + $hash_match['score'];
                if (!$best_match || $hash_score > $best_match['score']) {
                    $best_match = [
                        'field' => 'document_hash',
                        'label' => $this->Translator->trans('search.fields.document_hash'),
                        'display' => $Contract->hash,
                        'match' => $hash_match,
                        'score' => $hash_score,
                    ];
                }
            }

            if (!$best_match) {
                continue;
            }

            $subtitle = $Contract->hash_short;
            $results[] = $this->finalizeResult([
                'entity_type' => 'document',
                'entity_type_label' => $this->Translator->trans('search.types.document'),
                'entity_id' => $Contract->hash,
                'url' => SimpleRouter::getUrl('document_page', ['id' => $Contract->hash]),
                'icon_class' => 'fa-regular fa-file-lines',
                'title' => $name,
                'title_parts' => $this->makeHighlightParts($name, $query),
                'subtitle' => $subtitle,
                'subtitle_parts' => $this->makeHighlightParts($subtitle, $query),
                'match_label' => $best_match['label'],
                'match_field' => $best_match['field'],
                'match_kind' => $best_match['match']['kind'],
                'matched_value' => $best_match['display'],
                'match_parts' => $this->makeHighlightParts($best_match['display'], $query),
                'show_match_line' => $this->shouldShowMatchLine($best_match['display'], [$name, $subtitle]),
                'exact_match' => $best_match['match']['exact'],
                '_score' => 50 + $best_match['score'] + min(50, $using_count * 3),
                'title_sort' => mb_strtolower($name),
            ]);
        }

        return $results;
    }

    private function matchAccount(Account $Account, string $query, bool $show_telegram): ?array
    {
        $candidates = [];

        $candidates[] = [
            'field' => 'stellar_address',
            'label' => $this->Translator->trans('search.fields.stellar_address'),
            'text' => $Account->getId(),
            'display' => $Account->getId(),
            'weight' => 130,
        ];

        if ($username = $Account->getUsername()) {
            $candidates[] = [
                'field' => 'username',
                'label' => $this->Translator->trans('search.fields.username'),
                'text' => '@' . $username,
                'display' => '@' . $username,
                'weight' => 125,
            ];
            $candidates[] = [
                'field' => 'username',
                'label' => $this->Translator->trans('search.fields.username'),
                'text' => $username,
                'display' => '@' . $username,
                'weight' => 124,
            ];
        }

        if ($name = $Account->getName()[0] ?? null) {
            $candidates[] = [
                'field' => 'bsn_name',
                'label' => $this->Translator->trans('search.fields.bsn_name'),
                'text' => $name,
                'display' => $name,
                'weight' => 120,
            ];
        }

        if ($Account->isContact() && ($contact_name = $Account->getContactName())) {
            $candidates[] = [
                'field' => 'contact_name',
                'label' => $this->Translator->trans('search.fields.contact_name'),
                'text' => $contact_name,
                'display' => $contact_name,
                'weight' => 118,
            ];
        }

        if ($show_telegram && ($telegram_username = $Account->getTelegramUsername())) {
            $candidates[] = [
                'field' => 'telegram_username',
                'label' => $this->Translator->trans('search.fields.telegram_username'),
                'text' => '@' . $telegram_username,
                'display' => '@' . $telegram_username,
                'weight' => 110,
            ];
            $candidates[] = [
                'field' => 'telegram_username',
                'label' => $this->Translator->trans('search.fields.telegram_username'),
                'text' => $telegram_username,
                'display' => '@' . $telegram_username,
                'weight' => 109,
            ];
        }

        foreach ($Account->getWebsite() as $website) {
            $website = $this->normalizeInlineText($website);
            if ($website === '') {
                continue;
            }

            $candidates[] = [
                'field' => 'website',
                'label' => $this->Translator->trans('search.fields.website'),
                'text' => $website,
                'display' => $this->excerptAroundMatch($website, $query, 90),
                'weight' => 85,
            ];
        }

        foreach ($Account->getAbout() as $about) {
            $about = $this->normalizeInlineText($about);
            if ($about === '') {
                continue;
            }

            $candidates[] = [
                'field' => 'about',
                'label' => $this->Translator->trans('search.fields.about'),
                'text' => $about,
                'display' => $this->excerptAroundMatch($about, $query, 100),
                'weight' => 70,
            ];
        }

        $best_match = null;
        foreach ($candidates as $candidate) {
            $match = $this->matchText($query, $candidate['text']);
            if (!$match) {
                continue;
            }

            $score = $candidate['weight'] + $match['score'];
            if (!$best_match || $score > $best_match['score']) {
                $best_match = $candidate + [
                    'match' => $match,
                    'score' => $score,
                ];
            }
        }

        return $best_match;
    }

    private function finalizeResult(array $result): array
    {
        $result['subtitle'] ??= null;
        $result['subtitle_parts'] ??= [];
        $result['match_parts'] ??= [];
        $result['show_match_line'] ??= false;

        return $result;
    }

    private function resolveAccountTitle(Account $Account): string
    {
        return $Account->getName()[0]
            ?? $Account->getContactName()
            ?? ($Account->getUsername() ? '@' . $Account->getUsername() : null)
            ?? $Account->getShortId();
    }

    private function resolveAccountSubtitle(Account $Account, string $title): ?string
    {
        $variants = [];

        if ($Account->getUsername()) {
            $variants[] = '@' . $Account->getUsername();
        }

        if ($Account->getShortId() !== $title) {
            $variants[] = $Account->getShortId();
        }

        if ($Account->isContact() && ($contact_name = $Account->getContactName()) && $contact_name !== $title) {
            $variants[] = $contact_name;
        }

        foreach ($variants as $variant) {
            if ($variant !== $title) {
                return $variant;
            }
        }

        return null;
    }

    private function resolveAccountUrl(Account $Account): string
    {
        if ($username = $Account->getUsername()) {
            return '/@' . $username;
        }

        return SimpleRouter::getUrl('account', ['id' => $Account->getId()]);
    }
    private function resolveJsonLimit(): int
    {
        $limit = (int) ($_GET['limit'] ?? self::JSON_DEFAULT_LIMIT);
        if ($limit <= 0) {
            return self::JSON_DEFAULT_LIMIT;
        }

        return min($limit, self::JSON_MAX_LIMIT);
    }

    private function isJsonRequest(): bool
    {
        if (($_GET['format'] ?? '') === 'json') {
            return true;
        }

        return $this->acceptHeaderContainsJson($_SERVER['HTTP_ACCEPT'] ?? '');
    }

    private function resolveDirectEntityRedirect(string $query): ?string
    {
        if ($redirect_url = $this->resolveUnknownAccountRedirect($query)) {
            return $redirect_url;
        }

        return $this->resolveTransactionRedirect($query);
    }

    private function resolveUnknownAccountRedirect(string $query): ?string
    {
        if (!BSN::validateStellarAccountIdFormat($query)) {
            return null;
        }

        if ($this->BSN->getAccountById($query) !== null) {
            return null;
        }

        return SimpleRouter::getUrl('account', ['id' => $query]);
    }

    private function resolveTransactionRedirect(string $query): ?string
    {
        if (!BSN::validateTransactionHashFormat($query)) {
            return null;
        }

        $hash = strtolower($query);
        if ($this->DocumentsManager->getDocument($hash) !== null) {
            return null;
        }

        return SimpleRouter::getUrl('transaction_page', ['tx_hash' => $hash]);
    }

    private function acceptHeaderContainsJson(string $header): bool
    {
        foreach (explode(',', $header) as $accept_type) {
            $accept_type = trim(strtolower(explode(';', $accept_type)[0] ?? ''));
            if ($accept_type === 'application/json' || str_ends_with($accept_type, '+json')) {
                return true;
            }
        }

        return false;
    }

    private function matchText(string $query, string $text): ?array
    {
        $query = trim($query);
        $text = trim($text);
        if ($query === '' || $text === '') {
            return null;
        }

        $position = mb_stripos($text, $query);
        if ($position === false) {
            return null;
        }

        $query_length = mb_strlen($query);
        $text_length = mb_strlen($text);
        $exact = mb_strtolower($text) === mb_strtolower($query);
        $prefix = $position === 0;
        $word_start = !$prefix && $this->isWordBoundary($text, $position);

        $score = $exact ? 400 : ($prefix ? 260 : ($word_start ? 190 : 130));
        $score -= min(40, max(0, $text_length - $query_length));

        return [
            'position' => $position,
            'exact' => $exact,
            'prefix' => $prefix,
            'kind' => $exact ? 'exact' : ($prefix ? 'prefix' : ($word_start ? 'word' : 'substring')),
            'score' => $score,
        ];
    }

    private function makeHighlightParts(string $text, string $query): array
    {
        if ($text === '' || trim($query) === '') {
            return [['text' => $text, 'match' => false]];
        }

        $parts = [];
        $offset = 0;
        $query_length = mb_strlen($query);

        while (($position = mb_stripos($text, $query, $offset)) !== false) {
            if ($position > $offset) {
                $parts[] = [
                    'text' => mb_substr($text, $offset, $position - $offset),
                    'match' => false,
                ];
            }

            $parts[] = [
                'text' => mb_substr($text, $position, $query_length),
                'match' => true,
            ];
            $offset = $position + $query_length;
        }

        if ($offset < mb_strlen($text)) {
            $parts[] = [
                'text' => mb_substr($text, $offset),
                'match' => false,
            ];
        }

        return $parts ?: [['text' => $text, 'match' => false]];
    }

    private function shouldShowMatchLine(string $matched_value, array $visible_lines): bool
    {
        $matched_value = trim($matched_value);
        if ($matched_value === '') {
            return false;
        }

        foreach ($visible_lines as $line) {
            if ($line !== null && mb_strtolower(trim($line)) === mb_strtolower($matched_value)) {
                return false;
            }
        }

        return true;
    }

    private function isWordBoundary(string $text, int $position): bool
    {
        if ($position <= 0) {
            return true;
        }

        $previous_character = mb_substr($text, $position - 1, 1);
        return !preg_match('/[\p{L}\p{N}]/u', $previous_character);
    }

    private function normalizeInlineText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        return trim($text);
    }

    private function excerptAroundMatch(string $text, string $query, int $limit): string
    {
        $text = $this->normalizeInlineText($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $position = mb_stripos($text, $query);
        if ($position === false) {
            return rtrim(mb_substr($text, 0, max(0, $limit - 1))) . '…';
        }

        $query_length = mb_strlen($query);
        $start = max(0, $position - max(0, intdiv($limit - $query_length, 2)));
        $excerpt = mb_substr($text, $start, $limit);

        if ($start > 0) {
            $excerpt = '…' . ltrim($excerpt);
        }
        if (($start + $limit) < mb_strlen($text)) {
            $excerpt = rtrim($excerpt) . '…';
        }

        return $excerpt;
    }

}
