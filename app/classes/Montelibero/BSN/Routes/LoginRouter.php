<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\LoginController;
use Pecee\SimpleRouter\SimpleRouter;

class LoginRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::match(['get', 'post'], '/', function () use ($Container) {
            return $Container->get(LoginController::class)->Login();
        });
        SimpleRouter::match(['get', 'post'], '/manual', function () use ($Container) {
            return $Container->get(LoginController::class)->LoginManual();
        });
        SimpleRouter::match(['get', 'post'], '/callback', function () use ($Container) {
            return $Container->get(LoginController::class)->Callback();
        });
    }
}