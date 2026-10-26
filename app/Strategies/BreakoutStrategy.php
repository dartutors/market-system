<?php
declare(strict_types=1);
namespace App\Strategies;

use App\Strategies\Contracts\StrategyInterface;

/**
 * New strategy, not selected as default. Reacts to price breaking the prior
 * N-bar high/low, rather than moving-average structure or oscillators.
 */
final class BreakoutStrategy implements StrategyInterface
{
    private const LOOKBACK = 20;

    public function name(): string
    {
        return 'breakout';
    }

    public function evaluate(array $rows, array $sentimentSummary): array
    {
        $count   = count($rows);
        $latest  = $rows[$count - 1];
        $reasons = [];

        $lookback  = min(self::LOOKBACK, $count - 1);
        $priorRows = array_slice($rows, $count - 1 - $lookback, $lookback);
        $priorHigh = max(array_column($priorRows, 'high'));
        $priorLow  = min(array_column($priorRows, 'low'));
        $close     = (float) $latest['close'];
        $atr       = max((float) $latest['atr_14'], 0.0001);

        $action = 'HOLD'; $confidence = 50.0;

        if ($close > $priorHigh) {
            $action       = 'BUY';
            $breakoutSize = ($close - $priorHigh) / $atr;
            $confidence   = min(55 + ($breakoutSize * 15), 82);
            $reasons[]    = "Price broke above the prior {$lookback}-bar high (" . round($priorHigh, 5) . ') — bullish breakout.';
        } elseif ($close < $priorLow) {
            $action       = 'SELL';
            $breakoutSize = ($priorLow - $close) / $atr;
            $confidence   = min(55 + ($breakoutSize * 15), 82);
            $reasons[]    = "Price broke below the prior {$lookback}-bar low (" . round($priorLow, 5) . ') — bearish breakout.';
        } else {
            $reasons[] = "Price is inside the prior {$lookback}-bar range — no breakout.";
        }

        $sentimentScore = (float) ($sentimentSummary['score'] ?? 0.0);
        if ($action === 'BUY' && $sentimentScore <= -15) {
            $confidence -= 8; $reasons[] = 'News sentiment conflicts with the breakout — confidence reduced.';
        } elseif ($action === 'SELL' && $sentimentScore >= 15) {
            $confidence -= 8; $reasons[] = 'News sentiment conflicts with the breakout — confidence reduced.';
        }

        return ['action' => $action, 'confidence' => round(max(0.0, $confidence), 2), 'reasons' => $reasons];
    }
}
