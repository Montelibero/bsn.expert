<?php
namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\ApiContactsController;
use Montelibero\BSN\Controllers\ApiController;
use Pecee\SimpleRouter\SimpleRouter;

class ApiRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(ApiController::class)->ApiIndex();
        });
        SimpleRouter::post('/contacts/sync', function () use ($Container) {
            return $Container->get(ApiContactsController::class)->Sync();
        })->name('api_contacts_sync');
    }
}
