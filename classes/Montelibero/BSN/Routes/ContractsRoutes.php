<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\ContractsController;
use Pecee\SimpleRouter\SimpleRouter;

class ContractsRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(ContractsController::class)->Contracts();
        });
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(ContractsController::class)->Contract($id);
        })->name('contract');
        SimpleRouter::get('/{id}/text', function ($id) use ($Container) {
            return $Container->get(ContractsController::class)->ContractText($id);
        })->name('contract_text');
        SimpleRouter::match(['get', 'post'], '/{id}/sign', function ($id) use ($Container) {
            return $Container->get(ContractsController::class)->ContractSign($id);
        });
    }
}
