<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\DocumentsController;
use Pecee\SimpleRouter\SimpleRouter;

class DocumentsRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(DocumentsController::class)->Documents();
        });
        SimpleRouter::get('/update_from_grist', function () use ($Container) {
            return $Container->get(DocumentsController::class)->UpdateFromGrist();
        });
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(DocumentsController::class)->Document($id);
        })->name('contract');
        SimpleRouter::get('/{id}/text', function ($id) use ($Container) {
            return $Container->get(DocumentsController::class)->DocumentText($id);
        })->name('contract_text');
        SimpleRouter::match(['get', 'post'], '/{id}/sign', function ($id) use ($Container) {
            return $Container->get(DocumentsController::class)->DocumentSign($id);
        });
    }
}
