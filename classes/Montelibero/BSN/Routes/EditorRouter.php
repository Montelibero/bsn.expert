<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;

class EditorRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(WebApp::class)->EditorForm();
        });

        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(WebApp::class)->Editor($id);
        })->name('editor');

        SimpleRouter::post('/{id}', function ($id) use ($Container) {
            return $Container->get(WebApp::class)->EditorSave($id);
        });
    }
} 