<?php

namespace Montelibero\BSN\Routes;

use DI\Container;
use Montelibero\BSN\Controllers\MembershipDistributionController;
use Montelibero\BSN\Controllers\MtlaRpExclusionController;
use Montelibero\BSN\Controllers\MtlaRpReportController;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\PercentPayController;
use Montelibero\BSN\Controllers\DecisionTransactionsController;
use Montelibero\BSN\Controllers\RecommendVerificationController;
use Montelibero\BSN\Controllers\SendTimeTokensController;
use Montelibero\BSN\Controllers\TimeTokenController;
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

        SimpleRouter::get('/mtla/rp_report', function () use ($Container) {
            return $Container->get(MtlaRpReportController::class)->MtlaRpReport();
        });

        SimpleRouter::match(['get', 'post'], '/timetoken', function () use ($Container) {
            return $Container->get(TimeTokenController::class)->TimeToken();
        })->name('tool_timetoken');

        SimpleRouter::match(['get', 'post'], '/xdr2lab', function () use ($Container) {
            return $Container->get(XdrToLabController::class)->XdrToLab();
        });
    }
} 
