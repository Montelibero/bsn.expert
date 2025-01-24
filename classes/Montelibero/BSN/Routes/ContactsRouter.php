<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;

class ContactsRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::match(['get', 'post'], '/', function () use ($Container) {
            return $Container->get(WebApp::class)->Contacts();
        });

        SimpleRouter::match(['get', 'post'], '/{id}', function ($id) use ($Container) {
            return $Container->get(WebApp::class)->ContactsEdit($id);
        });
    }
} 