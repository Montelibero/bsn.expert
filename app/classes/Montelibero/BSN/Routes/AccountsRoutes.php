<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\TransactionsController;
use Pecee\SimpleRouter\SimpleRouter;

class AccountsRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(AccountsController::class)->Accounts();
        });
        SimpleRouter::get('/{id}/operations', function ($id) use ($Container) {
            $AccountsManager = $Container->get(AccountsManager::class);
            if ($username = $AccountsManager->fetchUsername($id)) {
                $query_string = $_SERVER['QUERY_STRING'] ?? '';
                $redirect_url = '/@' . $username . '/operations';
                if ($query_string) {
                    $redirect_url .= '?' . $query_string;
                }
                SimpleRouter::response()->redirect($redirect_url);
                return null;
            }
            return $Container->get(TransactionsController::class)->AccountOperations($id);
        })->name('account_operations');
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            if ($username = $Container->get(AccountsManager::class)->fetchUsername($id)) {
                SimpleRouter::response()->redirect('/@' . $username);
            }
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
