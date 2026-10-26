<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

/**
 * The 8-timeframe interval/range table, verified live against Yahoo
 * Finance's actual chart API before this was written: `4h` is a genuine
 * native interval (dataGranularity confirmed), and `5m`/`15m`/`30m` are
 * capped at a 60-day range by Yahoo (longer requests get HTTP 422) — still
 * thousands of bars, far more than indicators need.
 */
final class TimeframeRegistry
{
    /** Display order: highest timeframe first — matches TimeframeWeights' intent (higher TF = more context). */
    private const TIMEFRAMES = [
        'Monthly' => ['interval' => '1mo', 'range' => '10y'],
        'Weekly'  => ['interval' => '1wk', 'range' => '5y'],
        'Daily'   => ['interval' => '1d',  'range' => '2y'],
        'H4'      => ['interval' => '4h',  'range' => '3mo'],
        'H1'      => ['interval' => '60m', 'range' => '60d'],
        'M30'     => ['interval' => '30m', 'range' => '60d'],
        'M15'     => ['interval' => '15m', 'range' => '60d'],
        'M5'      => ['interval' => '5m',  'range' => '60d'],
    ];

    /** @return array<string, array{interval:string, range:string}> */
    public static function all(): array
    {
        return self::TIMEFRAMES;
    }

    /** @return array<string> Labels in highest-to-lowest display order. */
    public static function labels(): array
    {
        return array_keys(self::TIMEFRAMES);
    }

    public static function has(string $label): bool
    {
        return isset(self::TIMEFRAMES[$label]);
    }

    /** @return array{interval:string, range:string} */
    public static function get(string $label): array
    {
        if (!self::has($label)) {
            throw new \InvalidArgumentException("Unknown timeframe label: {$label}");
        }
        return self::TIMEFRAMES[$label];
    }
}
