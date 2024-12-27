<?php
namespace Montelibero\BSN;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TwigPluralizeExtension extends AbstractExtension
{
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function getFilters()
    {
        return [
            new TwigFilter('trans', [$this, 'transPlural']),
        ];
    }

    public function transPlural(string $key, $parameters = [], string $domain = 'messages', string $locale = null): string
    {
        // Устанавливаем локаль по умолчанию, если не указана
        $locale = $locale ?? $this->translator->getLocale();

        if ($locale === 'ru' && isset($parameters['%count%'])) {
            $catalogue = $this->translator->getCatalogue($locale);
            $translated = $catalogue->get($key, $domain);
            return $this->applyRussianRules($parameters['%count%'], $translated);
        }

        // Для остальных языков обрабатываем через стандартный trans()
        return $this->translator->trans($key, $parameters, $domain, $locale);
    }

    private function applyRussianRules(int $count, string $translated): string
    {
        // Разделяем строку на формы
        $forms = explode('|', $translated);

        // Заменяем плейсхолдеры
        return str_replace('%count%', $count, $this->textend($count, $forms[0], $forms[1], $forms[2]));
    }

    function textend($num, $v1, $v2, $v3): string
    {
        if (substr($num, -2) >= 11 & substr($num, -2) <= 19) {
            return $v3;
        } elseif (substr($num, -1) == 0) {
            return $v3;
        } elseif (substr($num, -1) == 1) {
            return $v1;
        } elseif (substr($num, -1) >= 2 & substr($num, -1) <= 4) {
            return $v2;
        } elseif (substr($num, -1) >= 5 & substr($num, -1) <= 9) {
            return $v3;
        }
    }
}
