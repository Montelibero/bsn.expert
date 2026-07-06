<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\AssetSwapController;
use Montelibero\BSN\Controllers\CloseTrustlinesController;
use Montelibero\BSN\Controllers\EurmtlReportController;
use Montelibero\BSN\Controllers\EurmtlReport2Controller;
use Montelibero\BSN\Controllers\MembershipDistributionController;
use Montelibero\BSN\Controllers\MigrationController;
use Montelibero\BSN\Controllers\MtlaRpExclusionController;
use Montelibero\BSN\Controllers\OrdersController;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\PercentPayController;
use Montelibero\BSN\Controllers\DecisionTransactionsController;
use Montelibero\BSN\Controllers\RecommendVerificationController;
use Montelibero\BSN\Controllers\SendTimeTokensController;
use Montelibero\BSN\Controllers\SwapController;
use Montelibero\BSN\Controllers\VotesController;
use Montelibero\BSN\Controllers\XdrToLabController;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class ToolsRouter
{
    public static function register(Container $Container): void
    {
        SimpleRouter::get('/', function () use ($Container) {
            return $Container->get(Environment::class)->render('tools.twig');
        });

        SimpleRouter::match(['get', 'post'], '/percent_pay', function () use ($Container) {
            return $Container->get(PercentPayController::class)->PercentPay();
        });

        SimpleRouter::match(['get', 'post'], '/multisig', function () use ($Container) {
            return $Container->get(MultisigController::class)->Multisig();
        })->name('multisig');

        SimpleRouter::get('/asset_swap', function () use ($Container) {
            return $Container->get(AssetSwapController::class)->AssetSwap();
        })->name('tool_asset_swap');

        SimpleRouter::get('/swap', function () use ($Container) {
            return $Container->get(SwapController::class)->Swap();
        })->name('tool_swap');

        SimpleRouter::match(['get', 'post'], '/migration', function () use ($Container) {
            return $Container->get(MigrationController::class)->Migration();
        })->name('tool_migration');

        SimpleRouter::match(['get', 'post'], '/close_trustlines', function () use ($Container) {
            return $Container->get(CloseTrustlinesController::class)->CloseTrustlines();
        })->name('tool_close_trustlines');

        SimpleRouter::match(['get', 'post'], '/orders', function () use ($Container) {
            return $Container->get(OrdersController::class)->Orders();
        })->name('tool_orders');

        SimpleRouter::match(['get', 'post'], '/orders/new', function () use ($Container) {
            return $Container->get(OrdersController::class)->NewOrder();
        })->name('tool_orders_new');

        SimpleRouter::get('/eurmtl_report', function () use ($Container) {
            return $Container->get(EurmtlReportController::class)->EurmtlReport();
        })->name('tool_eurmtl_report');

        SimpleRouter::get('/eurmtl_report2', function () use ($Container) {
            return $Container->get(EurmtlReport2Controller::class)->EurmtlReport2();
        })->name('tool_eurmtl_report2');

        SimpleRouter::match(['get', 'post'], '/mtla/membership_distribution', function () use ($Container) {
            return $Container->get(MembershipDistributionController::class)->MtlaMembershipDistribution();
        });

        SimpleRouter::match(['get', 'post'], '/mtla/rp_exclusion', function () use ($Container) {
            return $Container->get(MtlaRpExclusionController::class)->MtlaRpExclusion();
        });

        SimpleRouter::match(['get', 'post'], '/mtla/send_time_tokens', function () use ($Container) {
            return $Container->get(SendTimeTokensController::class)->MtlaSendTimeTokens();
        });

        SimpleRouter::match(['get', 'post'], '/mtla/decision_transactions', function () use ($Container) {
            return $Container->get(DecisionTransactionsController::class)->MtlaDecisionTransactions();
        });

        SimpleRouter::match(['get', 'post'], '/mtla/votes', function () use ($Container) {
            return $Container->get(VotesController::class)->MtlaVotes();
        });

        SimpleRouter::get('/mtla/recommend_verification', function () use ($Container) {
            return $Container->get(RecommendVerificationController::class)->MtlaRecommendVerification();
        });

        SimpleRouter::get('/mtla/rp_report', function () {
            SimpleRouter::response()->redirect('/mtla/dm_report', 301);
        });

        SimpleRouter::match(['get', 'post'], '/timetoken', function () {
            self::redirectToEditorTimetoken();
            return null;
        });

        SimpleRouter::match(['get', 'post'], '/xdr2lab', function () use ($Container) {
            return $Container->get(XdrToLabController::class)->XdrToLab();
        });
    }

    private static function redirectToEditorTimetoken(): void
    {
        $url = '/editor/timetoken';
        $query_string = $_SERVER['QUERY_STRING'] ?? '';
        if ($query_string !== '') {
            $url .= '?' . $query_string;
        }

        SimpleRouter::response()->redirect($url, 301);
    }
} 
