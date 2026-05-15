<?php

namespace Montelibero\BSN;

class ReturnTo
{
    public static function getFromRequest(string $fallback = '/', array $blocked_path_prefixes = ['login', 'logout']): string
    {
        return self::resolve([
            $_POST['return_to'] ?? null,
            $_GET['return_to'] ?? null,
            $_SERVER['HTTP_REFERER'] ?? null,
        ], $fallback, $blocked_path_prefixes);
    }

    public static function resolve(array $candidates, string $fallback = '/', array $blocked_path_prefixes = ['login', 'logout']): string
    {
        foreach ($candidates as $candidate) {
            $return_to = self::normalize($candidate, '', $blocked_path_prefixes);
            if ($return_to !== '') {
                return $return_to;
            }
        }

        return self::normalize($fallback, '/', $blocked_path_prefixes);
    }

    public static function normalize(?string $return_to, string $fallback = '/', array $blocked_path_prefixes = ['login', 'logout']): string
    {
        $fallback = $fallback === '' ? '' : (self::normalizeLocalPath($fallback, $blocked_path_prefixes) ?? '/');
        $return_to = trim((string) $return_to);
        if ($return_to === '') {
            return $fallback;
        }

        $parts = parse_url($return_to);
        if ($parts === false) {
            return $fallback;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (empty($parts['host']) || !self::isCurrentHost($parts)) {
                return $fallback;
            }

            $path = $parts['path'] ?? '/';
            if ($path === '') {
                $path = '/';
            }
            if (isset($parts['query']) && $parts['query'] !== '') {
                $path .= '?' . $parts['query'];
            }
            if (isset($parts['fragment']) && $parts['fragment'] !== '') {
                $path .= '#' . $parts['fragment'];
            }

            return self::normalizeLocalPath($path, $blocked_path_prefixes) ?? $fallback;
        }

        return self::normalizeLocalPath($return_to, $blocked_path_prefixes) ?? $fallback;
    }

    private static function normalizeLocalPath(string $return_to, array $blocked_path_prefixes): ?string
    {
        if (preg_match('/[\r\n]/', $return_to)) {
            return null;
        }

        if (!str_starts_with($return_to, '/') || str_starts_with($return_to, '//')) {
            return null;
        }

        foreach ($blocked_path_prefixes as $prefix) {
            if (preg_match('~^/' . preg_quote($prefix, '~') . '(?:[/?#]|$)~', $return_to)) {
                return null;
            }
        }

        return $return_to;
    }

    private static function isCurrentHost(array $url_parts): bool
    {
        $current_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $current_parts = parse_url('http://' . $current_host);
        if ($current_parts === false || empty($current_parts['host'])) {
            return false;
        }

        if (strtolower($url_parts['host']) !== strtolower($current_parts['host'])) {
            return false;
        }

        $current_port = $current_parts['port'] ?? null;
        $url_port = $url_parts['port'] ?? null;
        if ($current_port !== null || $url_port !== null) {
            return (int) $current_port === (int) $url_port;
        }

        return true;
    }
}
