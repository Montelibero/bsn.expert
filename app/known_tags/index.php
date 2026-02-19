<?php
declare(strict_types=1);

$default_lang = 'en';
$lang = strtolower((string) ($_GET['lang'] ?? $default_lang));
if ($lang !== 'ru') {
    $lang = $default_lang;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, If-None-Match, If-Modified-Since');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

$list_path = __DIR__ . '/list.json';
if (!is_readable($list_path)) {
    http_response_code(500);
    echo '{"error": "known tags source not found"}';
    exit;
}

$list = json_decode((string) file_get_contents($list_path), true);
if (!is_array($list)) {
    http_response_code(500);
    echo '{"error": "known tags source malformed"}';
    exit;
}

$translations = [];
$translation_path = __DIR__ . '/lang-' . $lang . '.json';
if (is_readable($translation_path)) {
    $parsed = json_decode((string) file_get_contents($translation_path), true);
    if (is_array($parsed)) {
        $translations = $parsed;
    }
}

if (!$translations && $lang !== $default_lang) {
    $fallback_path = __DIR__ . '/lang-' . $default_lang . '.json';
    if (is_readable($fallback_path)) {
        $parsed = json_decode((string) file_get_contents($fallback_path), true);
        if (is_array($parsed)) {
            $translations = $parsed;
            $translation_path = $fallback_path;
        }
    }
}

/**
 * @param string[] $paths
 */
function calcCacheMeta(array $paths): array
{
    $latest_mtime = 0;
    $parts = [];
    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $mtime = (int) (filemtime($path) ?: 0);
        $size = (int) (filesize($path) ?: 0);
        if ($mtime > $latest_mtime) {
            $latest_mtime = $mtime;
        }
        $parts[] = $path . ':' . $mtime . ':' . $size;
    }

    return [
        'latest_mtime' => $latest_mtime,
        'etag' => '"' . sha1(implode('|', $parts)) . '"',
    ];
}

function isNotModifiedByETag(string $request_if_none_match, string $etag): bool
{
    if (trim($request_if_none_match) === '*') {
        return true;
    }

    foreach (explode(',', $request_if_none_match) as $item) {
        $item = trim($item);
        if (str_starts_with($item, 'W/')) {
            $item = substr($item, 2);
        }
        if ($item === $etag) {
            return true;
        }
    }

    return false;
}

$cache_meta = calcCacheMeta([$list_path, $translation_path]);
$etag = $cache_meta['etag'];
$last_modified_unix = $cache_meta['latest_mtime'];

header('Cache-Control: public, max-age=600, must-revalidate');
header('ETag: ' . $etag);
if ($last_modified_unix > 0) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $last_modified_unix) . ' GMT');
}

$if_none_match = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
if ($if_none_match !== '' && isNotModifiedByETag($if_none_match, $etag)) {
    http_response_code(304);
    exit;
}

$if_modified_since = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
if ($if_modified_since !== '' && $last_modified_unix > 0) {
    $if_modified_since_unix = strtotime($if_modified_since);
    if ($if_modified_since_unix !== false && $if_modified_since_unix >= $last_modified_unix) {
        http_response_code(304);
        exit;
    }
}

foreach (['presentation', 'links'] as $section) {
    if (!is_array($list[$section] ?? null)) {
        continue;
    }
    foreach ($list[$section] as $tag => $params) {
        if (!is_array($params)) {
            $params = [];
        }
        $params['description'] = $translations[$tag] ?? null;
        $list[$section][$tag] = $params;
    }
}

echo json_encode(
    $list,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
