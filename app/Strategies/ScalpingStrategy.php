<?php
declare(strict_types=1);
namespace App\Strategies;

use App\Strategies\Contracts\StrategyInterface;

/**
 * New strategy, not selected as default. Reacts to smaller/faster moves
 * than TrendFollowingStrategy: fast-SMA-only structure check and tighter
 * RSI bands, no MACD requirement.
 */
final class ScalpingStrategy implements StrategyInterface
{
    public function name(): string
    {
        return 'scalping';
    }

    public function evaluate(array $rows, array $sentimentSummary): array
    {
        $latest  = $rows[count($rows) - 1];
        $score   = 0;
        $reasons = [];

        $close = (float) $latest['close'];
        $sma20 = (float) $latest['sma_20'];
        $rsi   = (float) $latest['rsi_14'];

        if ($close > $sma20) {
            $score += 1; $reasons[] = 'Price above the fast SMA20 — short-term bullish lean.';
        } elseif ($close < $sma20) {
            $score -= 1; $reasons[] = 'Price below the fast SMA20 — short-term bearish lean.';
        }

        if ($rsi < 40) {
            $score += 1; $reasons[] = 'RSI in a shallow oversold zone — scalp-buy zone.';
        } elseif ($rsi > 60) {
            $score -= 1; $reasons[] = 'RSI in a shallow overbought zone — scalp-sell zone.';
        }

        $sentimentScore = (float) ($sentimentSummary['score'] ?? 0.0);
        if ($sentimentScore >= 10) {
            $score += 1; $reasons[] = 'News sentiment mildly supports bullish bias.';
        } elseif ($sentimentScore <= -10) {
            $score -= 1; $reasons[] = 'News sentiment mildly supports bearish bias.';
        }

        $action = 'HOLD'; $confidence = 50.0;
        if ($score >= 2) {
            $action = 'BUY'; $confidence = min(55 + ($score * 8), 80);
        } elseif ($score <= -2) {
            $action = 'SELL'; $confidence = min(55 + (abs($score) * 8), 80);
        } elseif ($score !== 0) {
            $action = 'WAIT'; $confidence = 45 + (abs($score) * 5);
            $reasons[] = 'Setup is developing but not strong enough yet.';
        } else {
            $reasons[] = 'No short-term edge detected.';
        }

        return ['action' => $action, 'confidence' => round($confidence, 2), 'reasons' => $reasons];
    }
}
