<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\AccountsController;
use Pecee\SimpleRouter\Exceptions\NotFoundHttpException;
use Pecee\SimpleRouter\SimpleRouter;
use Montelibero\BSN\WebApp;

class RootRoutes
{
    public static function register(Container $Container, BSN $BSN, AccountsManager $AccountsManager): void
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
        SimpleRouter::group(['prefix' => '/contracts'], function () use ($Container) {
            ContractsRoutes::register($Container);
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

        SimpleRouter::match(['get', 'post'], '/preferences', function () use ($Container) {
            return $Container->get(WebApp::class)->Preferences();
        });
        SimpleRouter::get('/defaults', function() {
            SimpleRouter::response()->redirect('/preferences', 301);
        });

        // Обработка "динамического" маршрута для user
        SimpleRouter::get('/{username}', function($username) use ($Container, $BSN, $AccountsManager) {
            $has_at = str_starts_with($username, '@');
            $username = trim($username, '@');
            $account_id = $AccountsManager->fetchAccountIdByUsername($username);
            if ($account_id ?? null) {
                $Account = $BSN->makeAccountById($account_id);
                if ($Account->getUsername() === $username && $has_at) {
                    return $Container->get(AccountsController::class)->Account($account_id);
                } else {
                    SimpleRouter::response()->redirect('/@' . $Account->getUsername(), 302);
                }
            }

            throw new NotFoundHttpException();
        })->where(['username' => '\@?[a-zA-Z0-9_]+']);
    }
}
