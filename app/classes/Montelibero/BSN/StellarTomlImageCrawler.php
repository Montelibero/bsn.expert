<?php

namespace Montelibero\BSN;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class StellarTomlImageCrawler
{
    private const MAX_IMAGE_BYTES = 1048576;
    private const MAX_IMAGE_PIXELS = 16000000;
    private const MAX_OUTPUT_SIDE = 600;
    private const HTTP_TIMEOUT_SECONDS = 10;
    private const SUCCESS_RECHECK_SECONDS = 86400;
    private const ERROR_RECHECK_SECONDS = 21600;

    private const ALLOWED_MIME_TYPES = [
        'image/png' => true,
        'image/jpeg' => true,
        'image/gif' => true,
        'image/webp' => true,
        'image/avif' => true,
    ];

    public function __construct(
        private StellarTomlImageManager $ImageManager,
    ) {
    }

    public function refreshRelevantImages(string $home_domain, array $toml_doc, bool $force = false): array
    {
        $summary = [
            'tasks' => 0,
            'ok' => 0,
            'downloaded' => 0,
            'cached' => 0,
            'not_modified' => 0,
            'errors' => 0,
            'unsupported' => 0,
        ];
        $refs = [];
        $tasks = $this->buildTasks($home_domain, $toml_doc);
        $summary['tasks'] = count($tasks);

        foreach ($tasks as $task) {
            $result = $this->refreshTask($task, $force);
            $summary[$result['summary_key']]++;
            if (($result['status'] ?? null) === 'ok') {
                $summary['ok']++;
            }
            $refs[] = $this->buildRefDoc($task, $result);
        }

        $this->ImageManager->replaceDomainRefs($home_domain, $refs);

        return $summary;
    }

    private function buildTasks(string $home_domain, array $toml_doc): array
    {
        $tasks = [];
        $org_logo = $this->stringValue($toml_doc['documentation']['org_logo'] ?? null);

        if ($org_logo !== null) {
            foreach (($toml_doc['observed_accounts'] ?? []) as $account) {
                if (($account['mentioned'] ?? false) !== true) {
                    continue;
                }
                $account_id = strtoupper(trim((string) ($account['account_id'] ?? '')));
                if (!BSN::validateStellarAccountIdFormat($account_id)) {
                    continue;
                }
                $this->addTask($tasks, [
                    'home_domain' => $home_domain,
                    'entity_type' => 'account',
                    'entity_key' => $account_id,
                    'role' => 'org_logo',
                    'source_url' => $org_logo,
                ]);
            }
        }

        $currencies = [];
        foreach (($toml_doc['currencies'] ?? []) as $currency) {
            $key = strtoupper(trim((string) ($currency['key'] ?? '')));
            if ($key !== '') {
                $currencies[$key] = $currency;
            }
        }

        foreach (($toml_doc['observed_accounts'] ?? []) as $account) {
            $issuer = strtoupper(trim((string) ($account['account_id'] ?? '')));
            if (!BSN::validateStellarAccountIdFormat($issuer)) {
                continue;
            }

            foreach ((array) ($account['tokens'] ?? []) as $code) {
                $key = StellarTomlManager::tokenKey((string) $code, $issuer);
                $currency = $currencies[$key] ?? null;
                $image_url = $this->stringValue($currency['image'] ?? null);
                if ($currency === null || $image_url === null) {
                    continue;
                }
                $this->addTask($tasks, [
                    'home_domain' => $home_domain,
                    'entity_type' => 'token',
                    'entity_key' => $key,
                    'role' => 'token_image',
                    'source_url' => $image_url,
                ]);
            }
        }

        return array_values($tasks);
    }

    private function addTask(array &$tasks, array $task): void
    {
        $key = implode('|', [
            $task['entity_type'],
            $task['entity_key'],
            $task['role'],
            $task['source_url'],
        ]);
        $tasks[$key] = $task;
    }

    private function refreshTask(array $task, bool $force): array
    {
        $source_url = $this->normalizeImageUrl((string) $task['source_url']);
        if ($source_url === null) {
            return [
                'status' => 'error',
                'summary_key' => 'errors',
                'error' => ['code' => 'invalid_url', 'message' => 'Invalid image URL'],
            ];
        }
        $task['source_url'] = $source_url;

        $image_id = StellarTomlImageManager::imageId($source_url);
        $existing = $this->ImageManager->fetchImageByUrlRaw($source_url);
        if (!$force && $this->canUseCachedImage($existing)) {
            $entity_target = $this->ensureEntityFile($task, $existing);

            return [
                'status' => 'ok',
                'summary_key' => 'cached',
                'image' => $existing,
                'entity_file_path' => $entity_target['file_path'] ?? null,
                'public_path' => $entity_target['public_path'] ?? ($existing['public_path'] ?? null),
            ];
        }

        $existing_file_available = is_array($existing)
            && is_string($existing['file_path'] ?? null)
            && is_file($existing['file_path']);
        $fetch = $this->fetchImage($source_url, (!$force && $existing_file_available) ? $existing : null);
        if (($fetch['status'] ?? null) === 'not_modified' && $existing !== null) {
            $doc = $existing;
            $doc['status'] = 'ok';
            $doc['last_attempt_at'] = StellarTomlManager::now();
            $doc['last_success_at'] = StellarTomlManager::now();
            $doc['next_check_at'] = $this->future(self::SUCCESS_RECHECK_SECONDS);
            $doc['error'] = null;
            $this->ImageManager->saveImage($doc);
            $entity_target = $this->ensureEntityFile($task, $doc);

            return [
                'status' => 'ok',
                'summary_key' => 'not_modified',
                'image' => $doc,
                'entity_file_path' => $entity_target['file_path'] ?? null,
                'public_path' => $entity_target['public_path'] ?? ($doc['public_path'] ?? null),
            ];
        }

        if (($fetch['status'] ?? null) !== 'ok') {
            $doc = $this->buildFailedImageDoc($image_id, $source_url, $fetch);
            $this->ImageManager->saveImage($doc);

            return [
                'status' => 'error',
                'summary_key' => ($fetch['error']['code'] ?? null) === 'unsupported_type' ? 'unsupported' : 'errors',
                'image' => $doc,
                'error' => $fetch['error'] ?? ['code' => 'error', 'message' => 'Image fetch failed'],
            ];
        }

        $converted = $this->convertToPng($fetch['content']);
        if (($converted['status'] ?? null) !== 'ok') {
            $doc = $this->buildFailedImageDoc($image_id, $source_url, $converted);
            $this->ImageManager->saveImage($doc);

            return [
                'status' => 'error',
                'summary_key' => ($converted['error']['code'] ?? null) === 'unsupported_type' ? 'unsupported' : 'errors',
                'image' => $doc,
                'error' => $converted['error'] ?? ['code' => 'error', 'message' => 'Image conversion failed'],
            ];
        }

        $output_hash = hash('sha256', $converted['png']);
        $cache_target = $this->ImageManager->buildCacheTarget($image_id, $output_hash);
        $this->writeFile($cache_target['file_path'], $converted['png']);

        $doc = [
            'image_id' => $image_id,
            'source_url' => $source_url,
            'status' => 'ok',
            'etag' => $fetch['etag'] ?? null,
            'last_modified' => $fetch['last_modified'] ?? null,
            'source_content_hash' => hash('sha256', $fetch['content']),
            'output_content_hash' => $output_hash,
            'mime_type' => $converted['mime_type'],
            'source_size' => strlen($fetch['content']),
            'width' => $converted['width'],
            'height' => $converted['height'],
            'output_width' => $converted['output_width'],
            'output_height' => $converted['output_height'],
            'file_path' => $cache_target['file_path'],
            'public_path' => $cache_target['public_path'],
            'last_attempt_at' => StellarTomlManager::now(),
            'last_success_at' => StellarTomlManager::now(),
            'next_check_at' => $this->future(self::SUCCESS_RECHECK_SECONDS),
            'error' => null,
        ];
        $this->ImageManager->saveImage($doc);
        $entity_target = $this->ensureEntityFile($task, $doc);

        return [
            'status' => 'ok',
            'summary_key' => 'downloaded',
            'image' => $doc,
            'entity_file_path' => $entity_target['file_path'] ?? null,
            'public_path' => $entity_target['public_path'] ?? $cache_target['public_path'],
        ];
    }

    private function fetchImage(string $source_url, ?array $existing): array
    {
        $host = parse_url($source_url, PHP_URL_HOST);
        if (!is_string($host) || ($dns_error = $this->validateDomainDns($host)) !== null) {
            return ['status' => 'error', 'error' => $dns_error ?? ['code' => 'invalid_url', 'message' => 'Invalid image host']];
        }

        $headers = ['User-Agent' => 'BSN Viewer stellar.toml image crawler'];
        if (is_array($existing)) {
            if (is_string($existing['etag'] ?? null) && $existing['etag'] !== '') {
                $headers['If-None-Match'] = $existing['etag'];
            }
            if (is_string($existing['last_modified'] ?? null) && $existing['last_modified'] !== '') {
                $headers['If-Modified-Since'] = $existing['last_modified'];
            }
        }

        $client = new Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => $headers,
        ]);

        try {
            $response = $client->request('GET', $source_url, ['stream' => true]);
        } catch (ConnectException $e) {
            return ['status' => 'error', 'error' => $this->classifyGuzzleError($e, 'connect_error')];
        } catch (RequestException $e) {
            return ['status' => 'error', 'error' => $this->classifyGuzzleError($e, 'server_error')];
        } catch (GuzzleException $e) {
            return ['status' => 'error', 'error' => ['code' => 'server_error', 'message' => $e->getMessage()]];
        }

        $status_code = $response->getStatusCode();
        if ($status_code === 304) {
            return ['status' => 'not_modified'];
        }
        if ($status_code !== 200) {
            return [
                'status' => 'error',
                'error' => [
                    'code' => 'http_error',
                    'message' => 'HTTP ' . $status_code,
                    'http_status' => $status_code,
                ],
            ];
        }

        $content_length = $response->getHeaderLine('Content-Length');
        if ($content_length !== '' && (int) $content_length > self::MAX_IMAGE_BYTES) {
            return ['status' => 'error', 'error' => ['code' => 'too_large', 'message' => 'Content-Length exceeds 1 MB']];
        }

        $body = $response->getBody();
        $content = '';
        while (!$body->eof()) {
            $content .= $body->read(8192);
            if (strlen($content) > self::MAX_IMAGE_BYTES) {
                return ['status' => 'error', 'error' => ['code' => 'too_large', 'message' => 'Image exceeds 1 MB']];
            }
        }

        if ($content === '') {
            return ['status' => 'error', 'error' => ['code' => 'empty_file', 'message' => 'Image file is empty']];
        }

        return [
            'status' => 'ok',
            'content' => $content,
            'etag' => $response->getHeaderLine('ETag') ?: null,
            'last_modified' => $response->getHeaderLine('Last-Modified') ?: null,
        ];
    }

    private function convertToPng(string $content): array
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->buffer($content) ?: null;
        if (!is_string($mime_type) || !isset(self::ALLOWED_MIME_TYPES[$mime_type])) {
            return ['status' => 'error', 'error' => ['code' => 'unsupported_type', 'message' => 'Unsupported image MIME type']];
        }

        $size = @getimagesizefromstring($content);
        if (!is_array($size)) {
            return ['status' => 'error', 'error' => ['code' => 'invalid_image', 'message' => 'Image metadata cannot be read']];
        }

        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_IMAGE_PIXELS) {
            return ['status' => 'error', 'error' => ['code' => 'too_large', 'message' => 'Image dimensions are too large']];
        }

        $source = @imagecreatefromstring($content);
        if (!$source instanceof \GdImage) {
            return ['status' => 'error', 'error' => ['code' => 'invalid_image', 'message' => 'Image cannot be decoded']];
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $scale = min(1.0, self::MAX_OUTPUT_SIDE / max($width, $height));
        $output_width = max(1, (int) round($width * $scale));
        $output_height = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($output_width, $output_height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $output_width, $output_height, $transparent);

        imagecopyresampled($target, $source, 0, 0, 0, 0, $output_width, $output_height, $width, $height);

        ob_start();
        imagepng($target, null, 6);
        $png = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if ($png === '') {
            return ['status' => 'error', 'error' => ['code' => 'conversion_error', 'message' => 'PNG output is empty']];
        }

        return [
            'status' => 'ok',
            'png' => $png,
            'mime_type' => $mime_type,
            'width' => $width,
            'height' => $height,
            'output_width' => $output_width,
            'output_height' => $output_height,
        ];
    }

    private function ensureEntityFile(array $task, array $image_doc): ?array
    {
        $output_hash = (string) ($image_doc['output_content_hash'] ?? '');
        $source_file = (string) ($image_doc['file_path'] ?? '');
        if ($output_hash === '' || $source_file === '' || !is_file($source_file)) {
            return null;
        }

        $target = $this->ImageManager->buildEntityTarget(
            (string) $task['entity_type'],
            (string) $task['entity_key'],
            (string) $task['role'],
            $output_hash
        );
        if (!is_file($target['file_path'])) {
            $this->writeFile($target['file_path'], (string) file_get_contents($source_file));
        }

        return $target;
    }

    private function buildRefDoc(array $task, array $result): array
    {
        return [
            'entity_type' => $task['entity_type'],
            'entity_key' => $task['entity_key'],
            'role' => $task['role'],
            'source_url' => $this->normalizeImageUrl((string) $task['source_url']) ?? (string) $task['source_url'],
            'image_id' => isset($result['image']['image_id']) ? (string) $result['image']['image_id'] : null,
            'status' => $result['status'] ?? 'error',
            'public_path' => $result['public_path'] ?? null,
            'file_path' => $result['entity_file_path'] ?? null,
            'error' => $result['error'] ?? ($result['image']['error'] ?? null),
        ];
    }

    private function buildFailedImageDoc(string $image_id, string $source_url, array $result): array
    {
        return [
            'image_id' => $image_id,
            'source_url' => $source_url,
            'status' => 'error',
            'last_attempt_at' => StellarTomlManager::now(),
            'next_check_at' => $this->future(self::ERROR_RECHECK_SECONDS),
            'error' => $result['error'] ?? ['code' => 'error', 'message' => 'Image failed'],
        ];
    }

    private function canUseCachedImage(?array $existing): bool
    {
        if (!is_array($existing) || ($existing['status'] ?? null) !== 'ok') {
            return false;
        }
        $file_path = (string) ($existing['file_path'] ?? '');
        if ($file_path === '' || !is_file($file_path)) {
            return false;
        }
        $next_check_at = $existing['next_check_at'] ?? null;
        if ($next_check_at instanceof \MongoDB\BSON\UTCDateTime) {
            $next_check_ts = $next_check_at->toDateTime()->getTimestamp();
        } elseif (is_array($next_check_at)) {
            $next_check_ts = (int) ($next_check_at['ts'] ?? 0);
        } else {
            $next_check_ts = 0;
        }

        return $next_check_ts > time();
    }

    private function normalizeImageUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        if (($parts['user'] ?? null) !== null || ($parts['pass'] ?? null) !== null || ($parts['host'] ?? '') === '') {
            return null;
        }

        return $url;
    }

    private function validateDomainDns(string $host): ?array
    {
        $home_domain = StellarTomlManager::normalizeHomeDomain($host);
        if ($home_domain === null) {
            return ['code' => 'invalid_domain', 'message' => 'Invalid image domain'];
        }

        $records = @dns_get_record($home_domain, DNS_A + DNS_AAAA);
        if (!$records) {
            return ['code' => 'dns_error', 'message' => 'Image domain does not resolve'];
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['code' => 'invalid_domain', 'message' => 'Image domain resolves to private or reserved IP'];
            }
        }

        return null;
    }

    private function classifyGuzzleError(Throwable $e, string $fallback_code): array
    {
        $message = $e->getMessage();
        $code = str_contains(strtolower($message), 'timed out') || str_contains(strtolower($message), 'timeout')
            ? 'timeout'
            : $fallback_code;

        return ['code' => $code, 'message' => $message];
    }

    private function writeFile(string $file_path, string $content): void
    {
        $dir = dirname($file_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($file_path, $content, LOCK_EX);
    }

    private function future(int $seconds): \MongoDB\BSON\UTCDateTime
    {
        return new \MongoDB\BSON\UTCDateTime((time() + $seconds) * 1000);
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
