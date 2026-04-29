<?php

namespace Montelibero\BSN;

use Normalizer;
use Spoofchecker;
use Throwable;

final class ProfileSanitizer
{
    private const MAX_NAME_GRAPHEMES = 64;
    private const MAX_ABOUT_GRAPHEMES = 1000;
    private const MAX_WEBSITE_GRAPHEMES = 2048;
    private const MAX_DEFAULT_GRAPHEMES = 256;
    private const MAX_CONSECUTIVE_MARKS = 3;

    private static ?Spoofchecker $Spoofchecker = null;

    public static function sanitizeProfile(array $profile): array
    {
        $result = [];

        foreach ($profile as $field => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }

            $sanitized_values = [];
            foreach ($values as $value) {
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }

                $value = (string) $value;
                $sanitized = match ($field) {
                    'Name' => self::sanitizeName($value),
                    'About' => self::sanitizeAbout($value),
                    'Website' => self::sanitizeWebsite($value),
                    default => self::sanitizeDefault($value),
                };

                if ($sanitized !== '') {
                    $sanitized_values[] = $sanitized;
                }
            }

            if ($sanitized_values) {
                $result[$field] = $sanitized_values;
            }
        }

        return $result;
    }

    public static function sanitizeName(string $value): string
    {
        $value = self::replaceReservedNameCharacters($value);

        return self::sanitizeText($value, self::MAX_NAME_GRAPHEMES, true);
    }

    public static function sanitizeAbout(string $value): string
    {
        return self::sanitizeText($value, self::MAX_ABOUT_GRAPHEMES);
    }

    public static function sanitizeWebsite(string $value): string
    {
        return self::removeUnsafePercentEncodedCodepoints(
            self::sanitizeText($value, self::MAX_WEBSITE_GRAPHEMES)
        );
    }

    public static function sanitizeWebsiteDisplay(string $value): string
    {
        return self::sanitizeText($value, self::MAX_WEBSITE_GRAPHEMES);
    }

    private static function sanitizeDefault(string $value): string
    {
        return self::sanitizeText($value, self::MAX_DEFAULT_GRAPHEMES);
    }

    private static function replaceReservedNameCharacters(string $value): string
    {
        return strtr($value, [
            '[' => '⟦',
            ']' => '⟧',
            '⭐' => '★',
            '🌟' => '★',
        ]);
    }

    private static function sanitizeText(string $value, int $max_graphemes, bool $reject_suspicious = false): string
    {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = self::normalize($value);
        $value = self::normalizeWhitespace($value);
        $value = self::removeUnsafeCodepoints($value);
        $value = self::limitCombiningMarks($value);
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if ($reject_suspicious && self::isSuspicious($value)) {
            return '';
        }

        return self::truncateGraphemes($value, $max_graphemes);
    }

    private static function normalize(string $value): string
    {
        if (!class_exists(Normalizer::class)) {
            return $value;
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        return is_string($normalized) ? $normalized : $value;
    }

    private static function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }

    private static function removeUnsafeCodepoints(string $value): string
    {
        return preg_replace('/\p{C}+/u', '', $value) ?? '';
    }

    private static function removeUnsafePercentEncodedCodepoints(string $value): string
    {
        return preg_replace_callback('/(?:%[0-9A-Fa-f]{2})+/', static function (array $matches): string {
            $decoded = rawurldecode($matches[0]);

            if (!mb_check_encoding($decoded, 'UTF-8')) {
                return $matches[0];
            }

            $clean = self::removeUnsafeCodepoints(self::limitCombiningMarks($decoded));

            return $clean === $decoded ? $matches[0] : rawurlencode($clean);
        }, $value) ?? '';
    }

    private static function limitCombiningMarks(string $value): string
    {
        $value = preg_replace('/^\p{M}+/u', '', $value) ?? '';

        return preg_replace_callback(
            '/\p{M}{' . (self::MAX_CONSECUTIVE_MARKS + 1) . ',}/u',
            static fn(array $matches): string => mb_substr($matches[0], 0, self::MAX_CONSECUTIVE_MARKS, 'UTF-8'),
            $value
        ) ?? '';
    }

    private static function isSuspicious(string $value): bool
    {
        $spoofchecker = self::getSpoofchecker();
        if ($spoofchecker === null) {
            return false;
        }

        try {
            return $spoofchecker->isSuspicious($value);
        } catch (Throwable) {
            return false;
        }
    }

    private static function getSpoofchecker(): ?Spoofchecker
    {
        if (!class_exists(Spoofchecker::class)) {
            return null;
        }

        if (self::$Spoofchecker !== null) {
            return self::$Spoofchecker;
        }

        $spoofchecker = new Spoofchecker();
        $spoofchecker->setChecks(
            Spoofchecker::INVISIBLE
            | Spoofchecker::MIXED_NUMBERS
            | Spoofchecker::HIDDEN_OVERLAY
        );

        return self::$Spoofchecker = $spoofchecker;
    }

    private static function truncateGraphemes(string $value, int $max_graphemes): string
    {
        if (function_exists('grapheme_strlen') && function_exists('grapheme_substr')) {
            $length = grapheme_strlen($value);
            if ($length !== false && $length > $max_graphemes) {
                return (string) grapheme_substr($value, 0, $max_graphemes);
            }

            return $value;
        }

        if (mb_strlen($value, 'UTF-8') > $max_graphemes) {
            return mb_substr($value, 0, $max_graphemes, 'UTF-8');
        }

        return $value;
    }
}
