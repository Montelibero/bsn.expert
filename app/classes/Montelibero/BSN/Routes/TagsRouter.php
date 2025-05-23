<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\TagsController;
use Pecee\SimpleRouter\SimpleRouter;

class TagsRouter
{
    public static function register(Container $Container)
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(TagsController::class)->Tags();
        });
        SimpleRouter::get('/{id}', function ($id) use ($Container) {
            return $Container->get(TagsController::class)->Tag($id);
        })->name('tag');
    }
}