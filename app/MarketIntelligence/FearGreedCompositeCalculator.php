<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

/**
 * NOT a real market-wide crowd-sentiment index — this codebase has no
 * options-skew, put/call ratio, or survey data to build one from. This is
 * an internal composite derived from two signals the app already computes:
 * SentimentIndexService's AI-summary-derived bullish/bearish lean, adjusted
 * by VolatilityIndexCalculator's realized-volatility read (high volatility
 * pulls the score toward fear even in a bullish lean; low volatility
 * nudges it toward complacency/greed — the same directional logic real
 * fear/greed indices use volatility for). Always label this "Internal
 * composite" in the UI — never present it as a genuine market sentiment feed.
 */
final class FearGreedCompositeCalculator
{
    /**
     * @param array{index:float, label:string, bias:string, confidence:float, based_on:string} $sentimentIndex
     * @param array{overall_index:float, level:string, by_pair:array} $volatilityIndex
     * @return array{score:float, label:string}
     */
    public static function calculate(array $sentimentIndex, array $volatilityIndex): array
    {
        $sentimentComponent = ($sentimentIndex['index'] + 100.0) / 2.0; // -100..100 -> 0..100

        $volAdjustment = match ($volatilityIndex['level'] ?? 'normal') {
            'high'  => -15.0,
            'low'   => 5.0,
            default => 0.0,
        };

        $score = max(0.0, min(100.0, $sentimentComponent + $volAdjustment));

        $label = match (true) {
            $score >= 76.0 => 'Extreme Greed',
            $score >= 56.0 => 'Greed',
            $score >= 45.0 => 'Neutral',
            $score >= 25.0 => 'Fear',
            default        => 'Extreme Fear',
        };

        return ['score' => round($score, 1), 'label' => $label];
    }
}
