<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;

/** Price closes decisively beyond a prior consolidation range's high or low, optionally confirmed by a volume surge. */
final class BreakoutDetector implements PatternDetectorInterface
{
    private const LOOKBACK_BARS         = 20;
    private const BREAKOUT_BUFFER_PCT   = 0.001; // 0.1% beyond the range to count as genuine, not noise
    private const VOLUME_SURGE_MULTIPLE = 1.2;
    private const MIN_BARS              = 25;

    public function key(): string
    {
        return 'breakout';
    }

    public function label(): string
    {
        return 'Breakout';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $lookbackStart = $count - 1 - self::LOOKBACK_BARS;
        if ($lookbackStart < 0) {
            return [];
        }

        $rangeHigh = -INF;
        $rangeLow  = INF;
        $volumeSum = 0.0;
        $volumeCount = 0;
        for ($i = $lookbackStart; $i < $count - 1; $i++) {
            $rangeHigh = max($rangeHigh, (float) $rows[$i]['high']);
            $rangeLow  = min($rangeLow, (float) $rows[$i]['low']);
            $volume = (float) ($rows[$i]['volume'] ?? 0.0);
            if ($volume > 0.0) {
                $volumeSum += $volume;
                $volumeCount++;
            }
        }

        $latest = $rows[$count - 1];
        $close  = (float) $latest['close'];
        $latestVolume = (float) ($latest['volume'] ?? 0.0);
        $avgVolume    = $volumeCount > 0 ? $volumeSum / $volumeCount : 0.0;

        if ($close > $rangeHigh * (1 + self::BREAKOUT_BUFFER_PCT)) {
            $direction   = 'bullish';
            $brokenLevel = $rangeHigh;
        } elseif ($close < $rangeLow * (1 - self::BREAKOUT_BUFFER_PCT)) {
            $direction   = 'bearish';
            $brokenLevel = $rangeLow;
        } else {
            return [];
        }

        $volumeConfirmed  = $avgVolume > 0.0 && $latestVolume >= $avgVolume * self::VOLUME_SURGE_MULTIPLE;
        $breakDistancePct = abs($close - $brokenLevel) / $brokenLevel;

        $confidence = 55.0
            + min(1.0, $breakDistancePct / 0.01) * 20.0
            + ($volumeConfirmed ? 15.0 : 0.0);

        return [new PatternMatch(
            'breakout',
            'Breakout',
            $direction,
            round(min(95.0, $confidence), 1),
            $lookbackStart,
            $count - 1,
            (int) $rows[$lookbackStart]['timestamp'],
            (int) $latest['timestamp'],
            sprintf(
                'Price closed %s the %d-bar range %s (%.5f) by %.2f%%%s.',
                $direction === 'bullish' ? 'above' : 'below',
                self::LOOKBACK_BARS,
                $direction === 'bullish' ? 'high' : 'low',
                $brokenLevel,
                $breakDistancePct * 100,
                $volumeConfirmed ? ' with a volume surge' : ''
            ),
            [$rangeHigh, $rangeLow]
        )];
    }
}
