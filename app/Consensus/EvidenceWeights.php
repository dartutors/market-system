<?php
declare(strict_types=1);
namespace App\Consensus;

use App\Models\EvidenceSourceWeight;
use Throwable;

/**
 * The one place every adapter reads its weight from — makes "adaptive
 * weighting" (this phase's spec) mean something operationally instead of
 * being a hardcoded constant no admin can ever change. Falls back to the
 * adapter's own WEIGHT constant when no row exists for that source
 * (fresh install, or a source added after the weights table was seeded) —
 * never throws, never blocks evidence-gathering on a DB hiccup.
 *
 * Cached for the lifetime of one request/process — this is called once per
 * source per market per decision cycle (27 calls across a typical 3-market,
 * 9-source refresh), not a hot per-tick path, but there's no reason to
 * re-query the same 9 rows repeatedly within a single cron run either.
 */
final class EvidenceWeights
{
    private static ?array $cache = null;

    public static function for(string $sourceKey, float $default): float
    {
        if (self::$cache === null) {
            try {
                self::$cache = EvidenceSourceWeight::all();
            } catch (Throwable $e) {
                error_log('[EvidenceWeights] Failed to load weights, using adapter defaults: ' . $e->getMessage());
                self::$cache = [];
            }
        }

        return self::$cache[$sourceKey] ?? $default;
    }

    /** Cron/long-running processes should call this between refresh cycles if a weight change might have just been approved. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
