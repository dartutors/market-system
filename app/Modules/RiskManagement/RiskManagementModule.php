<?php
declare(strict_types=1);
namespace App\Modules\RiskManagement;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 2.5 — gated behind the RISK_MANAGEMENT feature flag
 * (default off). On-demand only, same pattern as MultiTimeframeModule and
 * TradeCoachModule: computes everything fresh per request, no cron wiring,
 * no new tables — position sizing, stop/take-profit/risk-reward, and the
 * daily/weekly/drawdown/exposure/correlation guardrails are all derived
 * from data that already exists (signal_events, live candle ATR).
 */
final class RiskManagementModule implements ModuleInterface
{
    public function name(): string { return 'risk_management'; }

    public function routes(): array { return ['/risk-management', '/risk-management/assess']; }

    public function services(): array
    {
        return [
            \App\Controllers\RiskManagementController::class,
            \App\Risk\RiskAssessmentEngine::class,
            \App\Risk\StopLossCalculator::class,
            \App\Risk\TakeProfitCalculator::class,
            \App\Risk\RiskRewardCalculator::class,
            \App\Risk\DailyRiskLimitChecker::class,
            \App\Risk\WeeklyRiskLimitChecker::class,
            \App\Risk\DrawdownGuard::class,
            \App\Risk\ExposureTracker::class,
            \App\Risk\CorrelationAnalyzer::class,
            \App\Risk\LiveAtrLookup::class,
            \App\Services\RiskCalculatorService::class,
        ];
    }

    public function config(): array { return ['FEATURE_RISK_MANAGEMENT']; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
