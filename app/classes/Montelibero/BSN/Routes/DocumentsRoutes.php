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
        SimpleRouter::get('/my', function () use ($Container) {
            return $Container->get(DocumentsController::class)->MyDocuments();
        });
        SimpleRouter::match(['get', 'post'], '/add', function () use ($Container) {
            return $Container->get(DocumentsController::class)->Add();
        });
        SimpleRouter::match(['get', 'post'], '/update_from_grist', function () use ($Container) {
            return $Container->get(DocumentsController::class)->UpdateFromGrist();
        });
        SimpleRouter::match(['get', 'post'], '/{id}/edit', function ($id) use ($Container) {
            return $Container->get(DocumentsController::class)->Edit($id);
        });
        SimpleRouter::get('/{id}/text', function ($id) use ($Container) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('document_page', ['id' => $id]));
        });
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(DocumentsController::class)->Document($id);
        })->name('document_page');
    }
}
