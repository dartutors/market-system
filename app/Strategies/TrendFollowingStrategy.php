<?php
declare(strict_types=1);
namespace App\Strategies;

use App\Strategies\Contracts\StrategyInterface;

/**
 * Extracted verbatim from the scoring block that used to live inline in
 * SignalEngineService::evaluate() — same thresholds, same score math, same
 * reason strings. This is the registry's default strategy (env
 * ACTIVE_STRATEGY defaults to 'trend_following').
 */
final class TrendFollowingStrategy implements StrategyInterface
{
    public function name(): string
    {
        return 'trend_following';
    }

    public function evaluate(array $rows, array $sentimentSummary): array
    {
        $latest   = $rows[count($rows) - 1];
        $previous = $rows[count($rows) - 2];
        $score    = 0;
        $reasons  = [];

        $close        = (float) $latest['close'];
        $sma20        = (float) $latest['sma_20'];
        $sma50        = (float) $latest['sma_50'];
        $rsi          = (float) $latest['rsi_14'];
        $macdHist     = (float) $latest['macd_hist'];
        $prevMacdHist = (float) $previous['macd_hist'];

        if ($close > $sma20 && $sma20 > $sma50) {
            $score += 2; $reasons[] = 'Bullish structure: price above SMA20 and SMA50.';
        } elseif ($close < $sma20 && $sma20 < $sma50) {
            $score -= 2; $reasons[] = 'Bearish structure: price below SMA20 and SMA50.';
        }
        if ($rsi < 32) {$score += 1; $reasons[] = 'RSI shows oversold recovery zone.';}
        elseif ($rsi > 68) {$score -= 1; $reasons[] = 'RSI shows overbought pressure.';}
        if ($macdHist > 0 && $prevMacdHist <= 0) {$score += 1; $reasons[] = 'MACD histogram turned positive.';}
        elseif ($macdHist < 0 && $prevMacdHist >= 0) {$score -= 1; $reasons[] = 'MACD histogram turned negative.';}

        $sentimentScore = (float) ($sentimentSummary['score'] ?? 0.0);
        if ($sentimentScore >= 15) {$score += 1; $reasons[] = 'News sentiment supports bullish bias.';}
        elseif ($sentimentScore <= -15) {$score -= 1; $reasons[] = 'News sentiment supports bearish bias.';}
        else {$reasons[] = 'News sentiment is neutral or mixed.';}

        $action = 'HOLD'; $confidence = 50.0;
        if ($score >= 3) {$action='BUY'; $confidence=min(55+($score*7.5),85);}
        elseif ($score <= -3) {$action='SELL'; $confidence=min(55+(abs($score)*7.5),85);}
        elseif (in_array($score,[1,2,-1,-2],true)) {$action='WAIT'; $confidence=45+(abs($score)*5); $reasons[]='Setup is not strong enough yet.';}
        else {$reasons[]='No clear edge detected.';}

        return [
            'action'     => $action,
            'confidence' => round($confidence, 2),
            'reasons'    => $reasons,
        ];
    }
}
