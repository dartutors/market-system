<?php
declare(strict_types=1);
namespace App\Config;

/**
 * Static config accessor for the Market Intelligence Engine (Phase 2.7),
 * mirroring App\Config\Risk's pattern: env-overridable with sane defaults,
 * no new required env vars.
 */
final class MarketIntelligence
{
    /** Lookback window (trading days) for the currency-strength "N-day return". */
    public static function returnLookbackDays(): int
    {
        return max(1, (int) env('MI_RETURN_LOOKBACK_DAYS', 5));
    }

    /** ATR-as-%-of-price below this is "low" volatility. */
    public static function volatilityLowThreshold(): float
    {
        return max(0.0, (float) env('MI_VOLATILITY_LOW_THRESHOLD', 0.3));
    }

    /** ATR-as-%-of-price at/above this is "high" volatility. */
    public static function volatilityHighThreshold(): float
    {
        return max(0.0, (float) env('MI_VOLATILITY_HIGH_THRESHOLD', 0.8));
    }

    /** |Pearson r| at/above this counts as a "strong" correlation. */
    public static function correlationStrongThreshold(): float
    {
        return min(1.0, max(0.0, (float) env('MI_CORRELATION_STRONG_THRESHOLD', 0.7)));
    }

    /** |Pearson r| at/above this (and below strong) counts as "moderate". */
    public static function correlationModerateThreshold(): float
    {
        return min(1.0, max(0.0, (float) env('MI_CORRELATION_MODERATE_THRESHOLD', 0.3)));
    }

    /**
     * risk_on/risk_off sentiment bias is a softer, less currency-specific
     * read than bullish_usd/bearish_usd, so it contributes less to the
     * signed sentiment index — this factor scales it down.
     */
    public static function sentimentRiskSoftening(): float
    {
        return min(1.0, max(0.0, (float) env('MI_SENTIMENT_RISK_SOFTENING', 0.6)));
    }

    /** news_items.impact_score (0-100) at/above this counts as "high impact". */
    public static function newsHighImpactThreshold(): float
    {
        return max(0.0, (float) env('MI_NEWS_HIGH_IMPACT_THRESHOLD', 60.0));
    }

    public static function newsLookbackCount(): int
    {
        return max(1, (int) env('MI_NEWS_LOOKBACK_COUNT', 12));
    }
}
