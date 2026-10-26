<?php
declare(strict_types=1);
namespace App\Vision;

/**
 * The 9-timeframe table for the AI Vision Multi-Timeframe Workspace
 * (Phase 3.2) — screenshots, not real candle data. Deliberately its own
 * catalog, not a reuse of App\MultiTimeframe\TimeframeRegistry (8 labels, no
 * M1, weights tuned for that module's real-candle confluence math) or
 * App\MarketData\Manager\TimeframeCatalog (data-fetch ranges, an unrelated
 * concern) — same "adding here would silently change something else"
 * precedent already established twice in this codebase.
 */
final class VisionTimeframeCatalog
{
    /** Higher timeframes weighted more — same "long-term establishes direction" precedence as every top-down methodology. */
    private const WEIGHTS = [
        'Monthly' => 9.0,
        'Weekly'  => 7.0,
        'Daily'   => 5.0,
        'H4'      => 3.5,
        'H1'      => 2.5,
        'M30'     => 1.5,
        'M15'     => 1.0,
        'M5'      => 0.6,
        'M1'      => 0.3,
    ];

    private const BUCKETS = [
        'long'   => ['Monthly', 'Weekly', 'Daily'],
        'medium' => ['H4', 'H1'],
        'short'  => ['M30', 'M15', 'M5', 'M1'],
    ];

    /** @return array<string> Labels, highest timeframe first. */
    public static function labels(): array
    {
        return array_keys(self::WEIGHTS);
    }

    /** @return array<string, float> */
    public static function weights(): array
    {
        return self::WEIGHTS;
    }

    public static function weightFor(string $label): float
    {
        return self::WEIGHTS[$label] ?? 0.0;
    }

    /** @return array<string, array<string>> */
    public static function buckets(): array
    {
        return self::BUCKETS;
    }

    public static function bucketFor(string $label): ?string
    {
        foreach (self::BUCKETS as $bucket => $labels) {
            if (\in_array($label, $labels, true)) {
                return $bucket;
            }
        }
        return null;
    }

    public static function has(string $label): bool
    {
        return isset(self::WEIGHTS[$label]);
    }

    public static function totalWeight(): float
    {
        return array_sum(self::WEIGHTS);
    }
}
