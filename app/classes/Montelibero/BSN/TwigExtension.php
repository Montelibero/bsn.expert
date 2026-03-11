<?php
namespace Montelibero\BSN;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TwigExtension extends AbstractExtension
{
//    private array $data;
//
//    public function __construct(array $data)
//    {
//        $this->data = $data;
//    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('account_short', [$this, 'accountShort']),
            new TwigFilter('hash_short', [$this, 'hashShort']),
            new TwigFilter('split_amount', [$this, 'splitAmount']),
            new TwigFilter('pretty_url', [$this, 'prettyUrl']),
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
