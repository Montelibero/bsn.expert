<?php
namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\GristWebhookController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\GristSyncService;
use Pecee\SimpleRouter\SimpleRouter;

class TokensRoutes
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(TokensController::class)->Tokens();
        });
        SimpleRouter::post('/reload_known_tokens', function () use ($Container) {
            return $Container->get(GristWebhookController::class)->receive(GristSyncService::KNOWN_TOKENS);
        });
        SimpleRouter::get('/XLM', function () use ($Container) {
            return $Container->get(TokensController::class)->TokenXLM();
        })->name('token_xlm');
        SimpleRouter::get('/{code}', function ($code) use ($Container) {
            return $Container->get(TokensController::class)->Token($code);
        })->name('token_page');
    }
}
