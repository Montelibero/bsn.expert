<?php
namespace Montelibero\BSN;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TwigExtension extends AbstractExtension
{
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('account_short', [$this, 'accountShort']),
            new TwigFilter('hash_short', [$this, 'hashShort']),
            new TwigFilter('split_amount', [$this, 'splitAmount']),
            new TwigFilter('pretty_url', [$this, 'prettyUrl']),
            new TwigFilter('period', [$this, 'period']),
//            new TwigFilter('html_account', [$this, 'htmlAccount'], [
//                'is_safe' => [
//                    'html'
//                ]
//            ]),
        ];
    }

    public function accountShort($account): string
    {
        return substr($account, 0, 2) . '…' . substr($account, -6);
    }

    public function hashShort($hash): string
    {
        return substr($hash, 0, 6) . '…' . substr($hash, -6);
    }

    public function splitAmount($value): array
    {
        $value = (string) $value;
        $parts = explode('.', $value, 2);
        $int = $parts[0] === '' ? '0' : $parts[0];
        $frac = $parts[1] ?? '';

        if ($frac === '') {
            return [
                'int' => $int,
                'frac_main' => '',
                'frac_trailing' => '',
                'all_zero_frac' => false,
                'has_fraction' => false,
            ];
        }

        $trimmed = rtrim($frac, '0');
        $trailing = substr($frac, strlen($trimmed));
        $all_zero = $trimmed === '' && $trailing !== '';

        return [
            'int' => $int,
            'frac_main' => $trimmed,
            'frac_trailing' => $trailing,
            'all_zero_frac' => $all_zero,
            'has_fraction' => true,
        ];
    }

    public function prettyUrl(?string $url): string
    {
        if (!$url) {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $result = '';

        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $result .= $parts['user'];
            if (isset($parts['pass'])) {
                $result .= ':' . $parts['pass'];
            }
            $result .= '@';
        }

        if (isset($parts['host'])) {
            $host = $parts['host'];
            if (function_exists('idn_to_utf8')) {
                $decoded_host = idn_to_utf8($host, IDNA_DEFAULT);
                if ($decoded_host !== false) {
                    $host = $decoded_host;
                }
            }
            $result .= $host;
        }

        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }

        $result .= $this->decodeUtf8PercentSequences($parts['path'] ?? '');

        if (isset($parts['query'])) {
            $result .= '?' . $this->decodeUtf8PercentSequences($parts['query']);
        }

        if (isset($parts['fragment'])) {
            $result .= '#' . $this->decodeUtf8PercentSequences($parts['fragment']);
        }

        return $result;
    }

    public function period(null|int|string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return $this->translator->trans('mtla_snapshot_box.empty');
        }

        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return $this->translator->trans('mtla_snapshot_box.empty');
        }

        $age_seconds = max(0, time() - $timestamp);
        if ($age_seconds < 60) {
            return $this->translator->trans('mtla_snapshot_box.just_now');
        }

        if ($age_seconds < 3600) {
            $count = max(1, (int) floor($age_seconds / 60));
            return $this->transPeriod('mtla_snapshot_box.age.minutes', $count);
        }

        if ($age_seconds < 86400) {
            $count = max(1, (int) floor($age_seconds / 3600));
            return $this->transPeriod('mtla_snapshot_box.age.hours', $count);
        }

        $count = max(1, (int) floor($age_seconds / 86400));
        return $this->transPeriod('mtla_snapshot_box.age.days', $count);
    }

    private function transPeriod(string $key, int $count): string
    {
        $translated = $this->translator->getCatalogue($this->translator->getLocale())->get($key, 'messages');
        $forms = explode('|', $translated);

        if (count($forms) < 3) {
            return $this->translator->trans($key, ['%count%' => $count]);
        }

        if ($this->translator->getLocale() === 'ru') {
            $choice = $this->chooseRussianPluralForm($count, $forms);
        } else {
            $choice = $count === 1 ? $forms[0] : ($forms[1] ?? $forms[0]);
        }

        return str_replace('%count%', (string) $count, $choice);
    }

    private function chooseRussianPluralForm(int $count, array $forms): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        if ($mod100 >= 11 && $mod100 <= 19) {
            return $forms[2];
        }

        if ($mod10 === 1) {
            return $forms[0];
        }

        if ($mod10 >= 2 && $mod10 <= 4) {
            return $forms[1];
        }

        return $forms[2];
    }

    private function decodeUtf8PercentSequences(string $value): string
    {
        return (string) preg_replace_callback('/(?:%[0-9A-Fa-f]{2})+/', function (array $matches): string {
            $decoded = rawurldecode($matches[0]);

            if (!mb_check_encoding($decoded, 'UTF-8')) {
                return $matches[0];
            }

            return preg_match('/[^\x20-\x7E]/u', $decoded) ? $decoded : $matches[0];
        }, $value);
    }
}
