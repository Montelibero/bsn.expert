<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\Editor2Controller;
use Montelibero\BSN\Controllers\ProfileEditorController;
use Pecee\SimpleRouter\SimpleRouter;

class Editor2Router
{
    public static function register(Container $Container): void
    {
        SimpleRouter::match(['get', 'post'], '/', function () use ($Container) {
            return $Container->get(Editor2Controller::class)->Editor();
        });

        SimpleRouter::match(['get', 'post', 'head'], '/profile', function () use ($Container) {
            return $Container->get(ProfileEditorController::class)->Profile();
        });

        // Legacy /editor compatibility: old direct edit links used /editor/{source_account_id}/.
        SimpleRouter::match(['get', 'post'], '/{legacy_source_id}', function ($legacy_source_id) use ($Container) {
            return $Container->get(Editor2Controller::class)->LegacyPathRedirect($legacy_source_id);
        });
    }
}
