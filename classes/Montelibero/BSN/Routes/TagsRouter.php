<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Pecee\SimpleRouter\SimpleRouter;
use Montelibero\BSN\WebApp;

class TagsRouter
{
    public static function register(Container $Container)
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(WebApp::class)->Tags();
        });
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(WebApp::class)->Tag($id);
        })->name('tag');
    }
}