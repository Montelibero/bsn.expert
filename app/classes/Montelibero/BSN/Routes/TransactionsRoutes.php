<?php
namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\TransactionsController;
use Pecee\SimpleRouter\SimpleRouter;

class TransactionsRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::match(['get', 'post'], '/', function () use ($Container) {
            return $Container->get(TransactionsController::class)->Index();
        })->name('transactions_index');

        SimpleRouter::get('/{tx_hash}', function ($tx_hash) use ($Container) {
            return $Container->get(TransactionsController::class)->Transaction($tx_hash);
        })->name('transaction_page');
    }
}
