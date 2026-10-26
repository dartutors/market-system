<?php
declare(strict_types=1);
namespace App\MarketData\Manager;

/**
 * The 9-timeframe table Phase 2.2A's brief calls for (Monthly through M1).
 * Deliberately separate from App\MultiTimeframe\TimeframeRegistry (which
 * has its own 8-timeframe table tuned specifically for that module's
 * confluence weighting) — adding M1 there, or changing its ranges, would
 * silently change how many bars MultiTimeframeDataFetcher pulls per run.
 * This catalog exists purely so the new Market Data Layer can describe
 * "any supported timeframe" without touching that module at all, per the
 * brief's explicit "do not change scanner behaviour yet."
 *
 * Deriv's `ticks_history` API has no native Weekly/Monthly candle
 * granularity (its largest supported granularity is 86400s = 1 day) — this
 * is a genuine platform limitation, not an oversight. Those two labels have
 * a null 'deriv_granularity_seconds', which is how
 * App\MarketData\Providers\DerivProvider::supportsTimeframe() and the
 * Manager both know to route Monthly/Weekly straight to Yahoo Finance
 * instead of attempting (and failing) a Deriv call first.
 */
final class TimeframeCatalog
{
    private const TIMEFRAMES = [
        'Monthly' => ['interval' => '1mo', 'range' => '10y', 'deriv_granularity_seconds' => null],
        'Weekly'  => ['interval' => '1wk', 'range' => '5y',  'deriv_granularity_seconds' => null],
        'Daily'   => ['interval' => '1d',  'range' => '2y',  'deriv_granularity_seconds' => 86400],
        'H4'      => ['interval' => '4h',  'range' => '3mo', 'deriv_granularity_seconds' => 14400],
        'H1'      => ['interval' => '60m', 'range' => '60d', 'deriv_granularity_seconds' => 3600],
        'M30'     => ['interval' => '30m', 'range' => '60d', 'deriv_granularity_seconds' => 1800],
        'M15'     => ['interval' => '15m', 'range' => '60d', 'deriv_granularity_seconds' => 900],
        'M5'      => ['interval' => '5m',  'range' => '60d', 'deriv_granularity_seconds' => 300],
        'M1'      => ['interval' => '1m',  'range' => '7d',  'deriv_granularity_seconds' => 60],
    ];

    /** @return array<string, array{interval:string, range:string, deriv_granularity_seconds:?int}> */
    public static function all(): array
    {
        return self::TIMEFRAMES;
    }

    /** @return array<string> Labels, highest timeframe first. */
    public static function labels(): array
    {
        return array_keys(self::TIMEFRAMES);
    }

    public static function has(string $label): bool
    {
        return isset(self::TIMEFRAMES[$label]);
    }

    /** @return array{interval:string, range:string, deriv_granularity_seconds:?int} */
    public static function get(string $label): array
    {
        if (!self::has($label)) {
            throw new \InvalidArgumentException("Unknown timeframe label: {$label}");
        }
        return self::TIMEFRAMES[$label];
    }

    public static function derivGranularitySeconds(string $label): ?int
    {
        return self::get($label)['deriv_granularity_seconds'];
    }
}
