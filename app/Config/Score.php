<?php
declare(strict_types=1);
namespace App\Config;

/**
 * Default ScoreEngine weights, as specified in the Phase 2 foundation brief.
 * This is new infrastructure consumed only by App\Decision\DecisionEngine —
 * it does not replace the existing confidence math inside SignalEngineService,
 * EntrySignalService, AiInsightService, or AiBacktestSummaryService.
 *
 * 'calibration' (Phase 2.8, AI Learning Engine) defaults to weight 0.0 —
 * since it already sums to 1.0 with the other six, adding it changes NOTHING
 * for any existing installation; App\Decision\DecisionEngine always computes
 * and passes the factor, but weight 0.0 means its contribution is always
 * exactly 0 regardless of the factor's value, until an operator explicitly
 * sets SCORE_WEIGHT_CALIBRATION to something nonzero (at which point every
 * weight is rescaled to sum to 1.0 again by normalized(), same as any other
 * env override today).
 */
final class Score
{
    private const DEFAULT_WEIGHTS = [
        'technical'   => 0.30,
        'momentum'    => 0.20,
        'news'        => 0.15,
        'calendar'    => 0.15,
        'backtest'    => 0.10,
        'ai'          => 0.10,
        'calibration' => 0.00,
    ];

    /**
     * @return array<string,float> Always sums to 1.0 (rescaled if env overrides don't).
     */
    public static function weights(): array
    {
        $weights = [];
        foreach (self::DEFAULT_WEIGHTS as $factor => $default) {
            $envKey            = 'SCORE_WEIGHT_' . strtoupper($factor);
            $weights[$factor]  = max(0.0, (float) env($envKey, $default));
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

        foreach ($weights as $factor => $value) {
            $weights[$factor] = $value / $sum;
        }

        return $weights;
    }
}
