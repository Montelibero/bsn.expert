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
        SimpleRouter::get('/mtla/members', function () use ($Container) {
            return $Container->get(ApiController::class)->MtlaMembers();
        })->name('api_mtla_members');
        SimpleRouter::options('/mtla/members', function () use ($Container) {
            return $Container->get(ApiController::class)->MtlaMembersPreflight();
        })->name('api_mtla_members_preflight');
        SimpleRouter::post('/contacts/sync', function () use ($Container) {
            return $Container->get(ApiContactsController::class)->Sync();
        })->name('api_contacts_sync');
        SimpleRouter::options('/contacts/sync', function () use ($Container) {
            return $Container->get(ApiContactsController::class)->Preflight();
        })->name('api_contacts_sync_preflight');
    }
}
