<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\AccountsController;
use Pecee\SimpleRouter\SimpleRouter;

class AccountsRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(AccountsController::class)->Accounts();
        });
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(AccountsController::class)->Account($id);
        })->name('account');
        SimpleRouter::get('/{id}/and', function ($id) use ($Container) {
            return $Container->get(AccountsController::class)->AccountAndList($id);
        })->name('account_and_list');
        SimpleRouter::get('{id1}/and/{id2}', function ($id1, $id2) use ($Container) {
            return $Container->get(AccountsController::class)->AccountAnd($id1, $id2);
        })->name('account_and');
    }
}
