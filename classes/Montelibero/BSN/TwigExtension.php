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
//            new TwigFilter('html_account', [$this, 'htmlAccount'], [
//                'is_safe' => [
//                    'html'
//                ]
//            ]),
        ];
    }

    public function accountShort($account): string
    {
        return substr($account, 0, 4) . '…' . substr($account, -4);
    }
}
