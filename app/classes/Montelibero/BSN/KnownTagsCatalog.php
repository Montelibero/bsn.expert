<?php

namespace Montelibero\BSN;

class KnownTagsCatalog
{
    private ?array $list = null;
    private array $translationsByLocale = [];

    public function __construct(
        private readonly RequestLocale $RequestLocale,
        private readonly string $directory,
    ) {
    }

    public function list(): array
    {
        if ($this->list === null) {
            $this->list = $this->readJson($this->directory . '/list.json');
        }

        return $this->list;
    }

    public function tagDescriptions(?string $locale = null): array
    {
        $translations = $this->translations($locale);

        return is_array($translations['tags'] ?? null) ? $translations['tags'] : $translations;
    }

    public function categoryName(string $categoryId, ?string $locale = null): string
    {
        $translations = $this->translations($locale);

        return is_string($translations['categories'][$categoryId] ?? null)
            ? $translations['categories'][$categoryId]
            : $categoryId;
    }

    public function translations(?string $locale = null): array
    {
        $locale ??= $this->RequestLocale->getLocale();
        if (!array_key_exists($locale, $this->translationsByLocale)) {
            $path = $this->directory . '/lang-' . $locale . '.json';
            if (!is_file($path)) {
                $path = $this->directory . '/lang-en.json';
            }

            $this->translationsByLocale[$locale] = $this->readJson($path);
        }

        return $this->translationsByLocale[$locale];
    }

    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
