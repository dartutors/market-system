<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Extracted verbatim from MarketSnapshotBuilder's private deriveTrend()/
 * deriveMomentum() methods (same SMA20/50/RSI thresholds) so the new
 * multi-timeframe fetcher can reuse the identical classification instead of
 * duplicating the thresholds a second time. MarketSnapshotBuilder now
 * delegates here — output verified byte-identical.
 */
final class TrendClassifier
{
    public static function classifyTrend(array $latestRow): string
    {
        $close = (float) ($latestRow['close']  ?? 0.0);
        $sma20 = (float) ($latestRow['sma_20'] ?? 0.0);
        $sma50 = (float) ($latestRow['sma_50'] ?? 0.0);

        if ($close > $sma20 && $sma20 > $sma50) {
            return 'bullish';
        }
        if ($close < $sma20 && $sma20 < $sma50) {
            return 'bearish';
        }
        return 'neutral';
    }

    public static function classifyMomentum(array $latestRow): string
    {
        $rsi = (float) ($latestRow['rsi_14'] ?? 50.0);

        if ($rsi >= 60.0) {
            return 'strengthening';
        }
        if ($rsi <= 40.0) {
            return 'weakening';
        }
        return 'neutral';
    }
}
