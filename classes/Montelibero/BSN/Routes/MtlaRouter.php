<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\MtlaController;
use Pecee\SimpleRouter\SimpleRouter;

class MtlaRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(MtlaController::class)->Mtla();
        });

        SimpleRouter::get('/council', function () use ($Container) {
            return $Container->get(MtlaController::class)->MtlaCouncil();
        });
    }
}