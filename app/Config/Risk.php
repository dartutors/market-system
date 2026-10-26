<?php
declare(strict_types=1);
namespace App\Config;

/**
 * Static config accessor for the Risk Management Suite (Phase 2.5), mirroring
 * App\Config\Score / App\Config\TimeframeWeights' pattern: env-overridable
 * with sane defaults, no new required env vars.
 */
final class Risk
{
    public static function maxDailyRiskPercent(): float
    {
        return max(0.1, (float) env('MAX_DAILY_RISK_PERCENT', 6.0));
    }

    public static function maxWeeklyRiskPercent(): float
    {
        return max(0.1, (float) env('MAX_WEEKLY_RISK_PERCENT', 15.0));
    }

    /** Consecutive-loss count that triggers drawdown protection guidance. */
    public static function drawdownLossStreakThreshold(): int
    {
        return max(1, (int) env('DRAWDOWN_LOSS_STREAK', 3));
    }

    /** Risk multiplier suggested once the drawdown streak threshold is hit. */
    public static function drawdownRiskMultiplier(): float
    {
        return min(1.0, max(0.0, (float) env('DRAWDOWN_RISK_MULTIPLIER', 0.5)));
    }

    /** Max net directional exposure (in open-position units) to any one currency before warning. */
    public static function maxExposurePerCurrency(): int
    {
        return max(1, (int) env('MAX_EXPOSURE_PER_CURRENCY', 2));
    }

    public static function minRiskRewardRatio(): float
    {
        return max(0.1, (float) env('MIN_RISK_REWARD_RATIO', 1.5));
    }

    public static function defaultAtrStopMultiple(): float
    {
        return max(0.1, (float) env('STOP_LOSS_ATR_MULTIPLE', 1.5));
    }

    /**
     * There is no real connected trading account anywhere in this app (it
     * never executes trades) — the Consensus Engine's Estimated Profit/Loss
     * figures need *some* balance to size a position against when computing
     * a trade plan automatically (no per-request form input like
     * RiskManagementController::assess() has). This is an illustrative
     * reference figure, always labeled as such in the UI, not a real balance.
     */
    public static function consensusReferenceBalance(): float
    {
        return max(1.0, (float) env('CONSENSUS_REFERENCE_BALANCE', 10000.0));
    }

    public static function consensusReferenceRiskPercent(): float
    {
        return min(100.0, max(0.01, (float) env('CONSENSUS_REFERENCE_RISK_PERCENT', 1.0)));
    }
}
