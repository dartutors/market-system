<?php
declare(strict_types=1);
namespace App\Strategies;

use App\Strategies\Contracts\StrategyInterface;

/**
 * New strategy, not selected as default. Stricter than TrendFollowingStrategy:
 * requires SMA structure AND MACD momentum to agree together before acting,
 * rather than accumulating independent points toward a threshold.
 */
final class SwingStrategy implements StrategyInterface
{
    public function name(): string
    {
        return 'swing';
    }

    public function evaluate(array $rows, array $sentimentSummary): array
    {
        $latest   = $rows[count($rows) - 1];
        $previous = $rows[count($rows) - 2];
        $reasons  = [];

        $close        = (float) $latest['close'];
        $sma20        = (float) $latest['sma_20'];
        $sma50        = (float) $latest['sma_50'];
        $rsi          = (float) $latest['rsi_14'];
        $macdHist     = (float) $latest['macd_hist'];
        $prevMacdHist = (float) $previous['macd_hist'];

        $bullishStructure = $close > $sma20 && $sma20 > $sma50;
        $bearishStructure = $close < $sma20 && $sma20 < $sma50;
        $macdTurnedUp     = $macdHist > 0 && $prevMacdHist <= 0;
        $macdTurnedDown   = $macdHist < 0 && $prevMacdHist >= 0;

        $action = 'HOLD'; $confidence = 50.0;

        if ($bullishStructure && ($macdTurnedUp || $macdHist > 0)) {
            $action     = 'BUY';
            $confidence = 78.0;
            $reasons[]  = 'Bullish structure confirmed by MACD momentum — swing setup.';
            if ($rsi >= 70) {
                $confidence = 62.0; $reasons[] = 'RSI is stretched — reduced confidence.';
            }
        } elseif ($bearishStructure && ($macdTurnedDown || $macdHist < 0)) {
            $action     = 'SELL';
            $confidence = 78.0;
            $reasons[]  = 'Bearish structure confirmed by MACD momentum — swing setup.';
            if ($rsi <= 30) {
                $confidence = 62.0; $reasons[] = 'RSI is stretched — reduced confidence.';
            }
        } elseif ($bullishStructure || $bearishStructure) {
            $action     = 'WAIT';
            $confidence = 48.0;
            $reasons[]  = 'Structure present but momentum has not confirmed yet.';
        } else {
            $reasons[] = 'No swing structure detected.';
        }

        $sentimentScore = (float) ($sentimentSummary['score'] ?? 0.0);
        if ($action === 'BUY' && $sentimentScore <= -15) {
            $confidence -= 10; $reasons[] = 'News sentiment conflicts with the bullish setup — confidence reduced.';
        } elseif ($action === 'SELL' && $sentimentScore >= 15) {
            $confidence -= 10; $reasons[] = 'News sentiment conflicts with the bearish setup — confidence reduced.';
        }

        return ['action' => $action, 'confidence' => round(max(0.0, $confidence), 2), 'reasons' => $reasons];
    }
}
