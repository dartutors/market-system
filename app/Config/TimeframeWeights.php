<?php
declare(strict_types=1);
namespace App\Config;

/**
 * Default multi-timeframe confluence weights — higher timeframes carry more
 * weight, per the Phase 2.3 deliverable ("stronger recommendations based on
 * higher-timeframe context"). Mirrors App\Config\Score's exact pattern:
 * env-overridable, normalized to sum to 1.0 as a misconfiguration guard.
 */
final class TimeframeWeights
{
    private const DEFAULT_WEIGHTS = [
        'Monthly' => 0.25,
        'Weekly'  => 0.20,
        'Daily'   => 0.18,
        'H4'      => 0.15,
        'H1'      => 0.12,
        'M30'     => 0.05,
        'M15'     => 0.03,
        'M5'      => 0.02,
    ];

    /**
     * @return array<string,float> Always sums to 1.0 (rescaled if env overrides don't).
     */
    public static function weights(): array
    {
        $weights = [];
        foreach (self::DEFAULT_WEIGHTS as $timeframe => $default) {
            $envKey            = 'TIMEFRAME_WEIGHT_' . strtoupper($timeframe);
            $weights[$timeframe] = max(0.0, (float) env($envKey, $default));
        }

        return self::normalized($weights);
    }

    private static function normalized(array $weights): array
    {
        $sum = array_sum($weights);

        if ($sum <= 0.0) {
            return self::DEFAULT_WEIGHTS;
        }
        if (abs($sum - 1.0) < 0.0001) {
            return $weights;
        }

        foreach ($weights as $timeframe => $value) {
            $weights[$timeframe] = $value / $sum;
        }

        return $weights;
    }
}
