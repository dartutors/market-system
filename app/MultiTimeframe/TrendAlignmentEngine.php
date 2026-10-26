<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

use App\Config\TimeframeWeights;
use DateTimeImmutable;

/**
 * Weighted-vote confluence across timeframes: each timeframe's trend casts
 * a +1 (bullish) / -1 (bearish) / 0 (neutral) vote weighted by
 * App\Config\TimeframeWeights (higher timeframes count more). The
 * normalized weighted sum both determines the overall bias and doubles as
 * the confidence score — full agreement across all weight approaches 100%,
 * an even split approaches 0%.
 */
final class TrendAlignmentEngine
{
    private const BIAS_THRESHOLD = 0.1;

    /** @param array<string, TimeframeAnalysis> $byTimeframe */
    public function analyze(string $symbol, array $byTimeframe): MultiTimeframeResult
    {
        $weights = TimeframeWeights::weights();

        $weightedSum = 0.0;
        $usedWeight  = 0.0;

        foreach ($byTimeframe as $label => $analysis) {
            $weight = $weights[$label] ?? 0.0;
            $vote   = match ($analysis->trend) {
                'bullish' => 1.0,
                'bearish' => -1.0,
                default   => 0.0,
            };
            $weightedSum += $vote * $weight;
            $usedWeight  += $weight;
        }

        // Rescale by the weight actually available so a partial timeframe
        // set (some fetches failed) doesn't automatically read as "no bias."
        $normalizedSum = $usedWeight > 0.0 ? $weightedSum / $usedWeight : 0.0;

        $overallBias = match (true) {
            $normalizedSum > self::BIAS_THRESHOLD  => 'bullish',
            $normalizedSum < -self::BIAS_THRESHOLD => 'bearish',
            default                                 => 'neutral',
        };

        $confidence = round(min(100.0, abs($normalizedSum) * 100), 2);

        $breakdown = [];
        $conflicts = [];

        foreach ($byTimeframe as $label => $analysis) {
            $status = $this->statusFor($analysis->trend, $overallBias);

            $breakdown[$label] = [
                'trend'    => $analysis->trend,
                'momentum' => $analysis->momentum,
                'status'   => $status,
                'weight'   => $weights[$label] ?? 0.0,
            ];

            if ($status === 'conflicting') {
                $conflicts[] = "{$label} shows a {$analysis->trend} trend while the overall bias is {$overallBias}.";
            }
        }

        return new MultiTimeframeResult(
            symbol: $symbol,
            overallBias: $overallBias,
            confidence: $confidence,
            breakdown: $breakdown,
            conflicts: $conflicts,
            generatedAt: new DateTimeImmutable('now'),
        );
    }

    private function statusFor(string $trend, string $overallBias): string
    {
        if ($overallBias === 'neutral' || $trend === 'neutral') {
            return 'neutral';
        }

        return $trend === $overallBias ? 'aligned' : 'conflicting';
    }
}
