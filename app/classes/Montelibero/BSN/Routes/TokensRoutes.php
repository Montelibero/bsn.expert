<?php
namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\TokensController;
use Pecee\SimpleRouter\SimpleRouter;

class TokensRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(TokensController::class)->Tokens();
        });
        SimpleRouter::match(['get', 'post'], '/reload_known_tokens', function () use ($Container) {
            $Container->get(TokensController::class)->reloadKnownTokens();
            return 'OK';
        });
        SimpleRouter::get('/XLM', function () use ($Container) {
            return $Container->get(TokensController::class)->TokenXLM();
        })->name('token_xlm');
        SimpleRouter::get('/{code}', function ($code) use ($Container) {
            return $Container->get(TokensController::class)->Token($code);
        })->name('token_page');
    }
}
