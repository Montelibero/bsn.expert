<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\FederationController;
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

        SimpleRouter::get('/search/', function () use ($Container) {
            return $Container->get(WebApp::class)->Search();
        });

        SimpleRouter::group(['prefix' => '/login'], function () use ($Container) {
            LoginRouter::register($Container);
        });
        SimpleRouter::get('/logout', function () use ($Container) {
            session_destroy();
            SimpleRouter::response()->redirect('/', 302);
        });
        SimpleRouter::group(['prefix' => '/accounts'], function () use ($Container) {
            AccountsRoutes::register($Container);
        });
        SimpleRouter::group(['prefix' => '/tokens'], function () use ($Container) {
            TokensRoutes::register($Container);
        });
        SimpleRouter::get('/token/{path?}', self::getRedirectCallbackTo('/tokens'))->where(['path' => '.*']);
        SimpleRouter::get('/assets/{path?}', self::getRedirectCallbackTo('/tokens'))->where(['path' => '.*']);
        SimpleRouter::get('/asset/{path?}', self::getRedirectCallbackTo('/tokens'))->where(['path' => '.*']);
        SimpleRouter::group(['prefix' => '/tags'], function () use ($Container) {
            TagsRouter::register($Container);
        });
        SimpleRouter::group(['prefix' => '/assets'], function () use ($Container) {
            AssetsRouter::register($Container);
        });
        // Редирект всех запросов /contracts/* на /documents/*
        SimpleRouter::get('/contracts/{path?}', self::getRedirectCallbackTo('/documents'))->where(['path' => '.*']);

        SimpleRouter::group(['prefix' => '/documents'], function () use ($Container) {
            DocumentsRoutes::register($Container);
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
        SimpleRouter::get('/federation', function() use ($Container) {
            return $Container->get(FederationController::class)->Federation();
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

            SimpleRouter::response()->httpCode(404);
        })->where(['username' => '\@?[a-zA-Z0-9_]+']);
    }

    private static function getRedirectCallbackTo(string $redirect_url): callable {
        return function ($path = '') use ($redirect_url) {
            $query_string = $_SERVER['QUERY_STRING'] ?? '';
            if (!empty($path)) {
                $redirect_url .= '/' . $path;
            }
            if (!empty($query_string)) {
                $redirect_url .= '?' . $query_string;
            }
            SimpleRouter::response()->redirect($redirect_url, 301);
        };
    }
}
