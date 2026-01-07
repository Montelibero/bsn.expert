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
}
