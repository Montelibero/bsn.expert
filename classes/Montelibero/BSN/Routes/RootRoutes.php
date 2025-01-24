<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Pecee\SimpleRouter\SimpleRouter;
use Montelibero\BSN\WebApp;

class RootRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(WebApp::class)->Index();
        })->name('root');
        SimpleRouter::get('/tg', function () use ($Container) {
            $Container->get(WebApp::class)->TgLogin();
        });
        SimpleRouter::get('/tg/logout', function () use ($Container) {
            $Container->get(WebApp::class)->TgLogout();
        });
        SimpleRouter::get('/login', function () use ($Container) {
            return $Container->get(WebApp::class)->Login();
        });

        SimpleRouter::get('/search/', function () use ($Container) {
            return $Container->get(WebApp::class)->Search();
        });

        SimpleRouter::group(['prefix' => '/accounts'], function () use ($Container) {
            AccountsRoutes::register($Container);
        });
        SimpleRouter::group(['prefix' => '/tags'], function () use ($Container) {
            TagsRouter::register($Container);
        });
        SimpleRouter::group(['prefix' => '/mtla'], function () use ($Container) {
            MtlaRouter::register($Container);
        });
        SimpleRouter::group(['prefix' => '/contacts'], function () use ($Container) {
            ContactsRouter::register($Container);
        });
        SimpleRouter::group(['prefix' => '/editor'], function () use ($Container) {
            EditorRouter::register($Container);
        });
        SimpleRouter::group(['prefix' => '/tools'], function () use ($Container) {
            ToolsRouter::register($Container);
        });

        SimpleRouter::match(['get', 'post'], '/defaults', function () use ($Container) {
            return $Container->get(WebApp::class)->Defaults();
        });
    }
}
