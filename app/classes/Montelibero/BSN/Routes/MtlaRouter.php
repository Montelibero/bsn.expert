<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\Controllers\MtlaDmReportController;
use Pecee\SimpleRouter\SimpleRouter;

class MtlaRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(MtlaController::class)->Mtla();
        });
        SimpleRouter::match(['get', 'post'], '/reload_members', function () use ($Container) {
            return $Container->get(MtlaController::class)->MtlaReloadMembers();
        });

        SimpleRouter::get('/council', function () use ($Container) {
            return $Container->get(MtlaController::class)->MtlaCouncil();
        });

        SimpleRouter::get('/participants', function () use ($Container) {
            return $Container->get(MtlaController::class)->MtlaParticipants();
        });

        SimpleRouter::get('/programs', function () use ($Container) {
            return $Container->get(MtlaController::class)->MtlaPrograms();
        });

        SimpleRouter::get('/programs/{account_id}', function ($account_id) use ($Container) {
            return $Container->get(MtlaController::class)->MtlaProgram($account_id);
        });

        SimpleRouter::get('/dm_report', function () use ($Container) {
            return $Container->get(MtlaDmReportController::class)->MtlaDmReport();
        });

        SimpleRouter::group(['prefix' => '/crowd'], function () use ($Container) {
            CrowdRouter::register($Container);
        });

        SimpleRouter::get('/rp_report', function () {
            SimpleRouter::response()->redirect('/mtla/dm_report', 301);
        });
    }
}
