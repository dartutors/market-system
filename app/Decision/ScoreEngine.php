<?php
declare(strict_types=1);
namespace App\Decision;

use App\Config\Score as ScoreConfig;

/**
 * Single place all future confidence scoring should go through — but today
 * the sole consumer is DecisionEngine. Existing services keep their own
 * confidence formulas untouched by design (see the foundation-refactor plan).
 */
final class ScoreEngine
{
    private const FACTORS = ['technical', 'momentum', 'news', 'calendar', 'backtest', 'ai', 'calibration'];

    /**
     * @param array<string,float> $factors Each pre-normalized 0-100; missing factors default to 50 (neutral).
     * @param array<string,float>|null $weights Override; defaults to App\Config\Score::weights().
     */
    public function score(array $factors, ?array $weights = null): ScoreResult
    {
        $weights = $weights ?? ScoreConfig::weights();

        $breakdown = [];
        $total     = 0.0;

        foreach (self::FACTORS as $factor) {
            $value        = max(0.0, min(100.0, (float) ($factors[$factor] ?? 50.0)));
            $weight       = (float) ($weights[$factor] ?? 0.0);
            $contribution = $value * $weight;

            $breakdown[$factor] = [
                'value'        => $value,
                'weight'       => $weight,
                'contribution' => round($contribution, 2),
            ];

            $total += $contribution;
        }

        return new ScoreResult(
            confidence: round(max(0.0, min(100.0, $total)), 2),
            breakdown: $breakdown,
            weights: $weights,
        );
    }
}
