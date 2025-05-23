<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\EditorController;
use Pecee\SimpleRouter\SimpleRouter;

class EditorRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(EditorController::class)->EditorForm();
        });

        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(EditorController::class)->Editor($id);
        })->name('editor');

        SimpleRouter::post('/{id}', function ($id) use ($Container) {
            return $Container->get(EditorController::class)->EditorSave($id);
        });
    }
} 