<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Pecee\SimpleRouter\SimpleRouter;
use Montelibero\BSN\WebApp;

class AccountsRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(WebApp::class)->Accounts();
        });
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(WebApp::class)->Account($id);
        })->name('account');
        SimpleRouter::get('/{id}/and', function ($id) use ($Container) {
            return $Container->get(WebApp::class)->AccountAndList($id);
        })->name('account_and_list');
        SimpleRouter::get('{id1}/and/{id2}', function ($id1, $id2) use ($Container) {
            return $Container->get(WebApp::class)->AccountAnd($id1, $id2);
        })->name('account_and');
    }
}
