<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\CrowdController;
use Pecee\SimpleRouter\SimpleRouter;

class CrowdRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(CrowdController::class)->Index();
        })->name('crowd');

        SimpleRouter::match(['get', 'post'], '/create', function () use ($Container) {
            return $Container->get(CrowdController::class)->Create();
        })->name('crowd_create');

        SimpleRouter::post('/{code}/action/{action}', function ($code, $action) use ($Container) {
            return $Container->get(CrowdController::class)->Action($code, $action);
        })->where(['code' => '[A-Za-z0-9]+', 'action' => '[a-z]+'])->name('crowd_project_action');

        SimpleRouter::get('/{code}', function ($code) use ($Container) {
            return $Container->get(CrowdController::class)->Project($code);
        })->where(['code' => '[A-Za-z0-9]+'])->name('crowd_project');
    }
}
