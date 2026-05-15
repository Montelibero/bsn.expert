<?php

namespace Montelibero\BSN;

class AssetVersions
{
    private const CACHE_TTL = 604800;

    public function __construct(
        private readonly string $public_dir,
    ) {
    }

    public function url(string $path): string
    {
        if (!$this->isLocalPath($path)) {
            return $path;
        }

        $version = $this->version($path);
        if ($version === null) {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $version;
    }

    private function version(string $path): ?string
    {
        $file_path = $this->resolveFilePath($path);
        if ($file_path === null || !is_file($file_path)) {
            return null;
        }

        $cache_key = 'asset_version:' . $path;
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cache_key, $success);
            if ($success && is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $hash = hash_file('sha256', $file_path);
        if ($hash === false) {
            return null;
        }

        $version = substr($hash, 0, 12);
        if (function_exists('apcu_store')) {
            apcu_store($cache_key, $version, self::CACHE_TTL);
        }

        return $version;
    }

    private function resolveFilePath(string $path): ?string
    {
        $path_part = parse_url($path, PHP_URL_PATH);
        if (!is_string($path_part) || !$this->isLocalPath($path_part)) {
            return null;
        }

        $relative_path = ltrim($path_part, '/');
        if ($relative_path === '' || str_contains($relative_path, '..')) {
            return null;
        }

        return rtrim($this->public_dir, '/') . '/' . $relative_path;
    }

    private function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//');
    }
}
