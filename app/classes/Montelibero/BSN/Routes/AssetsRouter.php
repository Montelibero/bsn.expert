<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\AssetsController;
use Pecee\SimpleRouter\SimpleRouter;

class AssetsRouter
{
    public static function register(Container $Container)
    {
        SimpleRouter::match(['get', 'post'], '/reload', function () use ($Container) {
            return $Container->get(AssetsController::class)->AssetsReload();
        });
    }
}