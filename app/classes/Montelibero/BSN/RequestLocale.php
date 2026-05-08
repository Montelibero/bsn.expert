<?php

namespace Montelibero\BSN;

class RequestLocale
{
    private string $locale;

    /**
     * @param string[] $supportedLocales
     */
    public function __construct(
        private readonly array $supportedLocales = ['en', 'ru'],
        private readonly string $defaultLocale = 'en',
    ) {
        $this->locale = $defaultLocale;
    }

    public function beginRequest(): void
    {
        $locale = $this->defaultLocale;
        if (stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ru') !== false && $this->isSupported('ru')) {
            $locale = 'ru';
        }

        $cookieLocale = $_COOKIE['language'] ?? null;
        if (
            is_string($cookieLocale)
            && preg_match('/^[a-z]{2}$/', $cookieLocale) === 1
            && $this->isSupported($cookieLocale)
        ) {
            $locale = $cookieLocale;
        }

        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function __toString(): string
    {
        return $this->locale;
    }

    private function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supportedLocales, true);
    }
}
