<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\MembershipDistributionController;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\PercentPayController;
use Montelibero\BSN\Controllers\SendTimeTokensController;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class ToolsRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(Environment::class)->render('tools.twig');
        });

        SimpleRouter::match(['get', 'post'], '/percent_pay', function () use ($Container) {
            return $Container->get(PercentPayController::class)->PercentPay();
        });

        SimpleRouter::match(['get', 'post'], '/multisig', function () use ($Container) {
            return $Container->get(MultisigController::class)->Multisig();
        })->name('multisig');

        SimpleRouter::match(['get', 'post'], '/mtla/membership_distribution', function () use ($Container) {
            return $Container->get(MembershipDistributionController::class)->MtlaMembershipDistribution();
        });

        SimpleRouter::match(['get', 'post'], '/mtla/send_time_tokens', function () use ($Container) {
            return $Container->get(SendTimeTokensController::class)->MtlaSendTimeTokens();
        });
    }
} 