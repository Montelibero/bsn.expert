<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\MembershipDistributionController;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\PercentPayController;
use Pecee\SimpleRouter\SimpleRouter;

class ToolsRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::match(['get', 'post'], '/percent_pay', function () use ($Container) {
            return $Container->get(PercentPayController::class)->PercentPay();
        });

        SimpleRouter::match(['get', 'post'], '/multisig', function () use ($Container) {
            return $Container->get(MultisigController::class)->Multisig();
        });

        SimpleRouter::match(['get', 'post'], '/mtla/membership_distribution', function () use ($Container) {
            return $Container->get(MembershipDistributionController::class)->MtlaMembershipDistribution();
        });
    }
} 