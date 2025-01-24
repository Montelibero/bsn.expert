<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;

class ToolsRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::match(['get', 'post'], '/percent_pay', function () use ($Container) {
            return $Container->get(WebApp::class)->PercentPay();
        });

        SimpleRouter::match(['get', 'post'], '/multisig', function () use ($Container) {
            return $Container->get(WebApp::class)->Multisig();
        });

        SimpleRouter::match(['get', 'post'], '/mtla/membership_distribution', function () use ($Container) {
            return $Container->get(WebApp::class)->MtlaMembershipDistribution();
        });
    }
} 