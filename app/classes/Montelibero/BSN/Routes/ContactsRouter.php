<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\ContactsController;
use Pecee\SimpleRouter\SimpleRouter;

class ContactsRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::match(['get', 'post'], '/', function () use ($Container) {
            return $Container->get(ContactsController::class)->Contacts();
        });

        SimpleRouter::match(['get', 'post'], '/{id}', function ($id) use ($Container) {
            return $Container->get(ContactsController::class)->ContactsEdit($id);
        });
    }
} 