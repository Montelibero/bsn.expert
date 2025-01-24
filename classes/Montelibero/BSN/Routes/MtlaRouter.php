<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;

class MtlaRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(WebApp::class)->Mtla();
        });

        SimpleRouter::get('/council', function () use ($Container) {
            return $Container->get(WebApp::class)->MtlaCouncil();
        });
    }
}