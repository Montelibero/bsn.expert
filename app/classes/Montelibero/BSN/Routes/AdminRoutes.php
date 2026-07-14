<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\AdminController;
use Pecee\SimpleRouter\SimpleRouter;

class AdminRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(AdminController::class)->Index();
        });
        SimpleRouter::match(['get', 'post'], '/tomls', function () use ($Container) {
            return $Container->get(AdminController::class)->Tomls();
        });
        $caches = function () use ($Container) {
            return $Container->get(AdminController::class)->Caches();
        };
        SimpleRouter::match(['get', 'post'], '/caches', $caches);
        SimpleRouter::match(['get', 'post'], '/caches/', $caches);
    }
}
