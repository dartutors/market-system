<?php
declare(strict_types=1);
namespace App\Patterns\Contracts;

use App\Patterns\Models\PatternMatch;

/**
 * One geometric chart-pattern detector. Input is the same enriched
 * OHLC-row-array shape App\Services\IndicatorService::enrich() produces
 * and every App\Strategies\* class already consumes (open/high/low/close/
 * volume/timestamp, plus indicator columns) — deliberately not the newer
 * App\MarketData\Models\Candle, since the live Scanner path only ever has
 * raw rows in hand (confirmed via research). A Candle[] caller can adapt
 * via Candle::toLegacyRow().
 */
interface PatternDetectorInterface
{
    /** Machine key, e.g. 'double_top'. */
    public function key(): string;

    /** Human label, e.g. 'Double Top'. */
    public function label(): string;

    /**
     * @param array<int, array<string, mixed>> $rows Chronological OHLC(V) rows.
     * @return array<int, PatternMatch> Empty when no instance is found.
     */
    public function detect(array $rows): array;
}
