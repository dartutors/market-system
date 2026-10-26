<?php
declare(strict_types=1);
namespace App\SmartMoney\Contracts;

use App\SmartMoney\Models\StructureEvent;

/**
 * One institutional-structure detector. Input is the same enriched
 * OHLC-row-array shape App\Services\IndicatorService::enrich() produces and
 * App\Patterns\Contracts\PatternDetectorInterface already standardized on —
 * kept identical on purpose so App\SmartMoney and App\Patterns can share a
 * data-fetch call site (see App\Controllers\SmartMoneyController).
 */
interface StructureDetectorInterface
{
    /** Machine key, e.g. 'order_block'. */
    public function key(): string;

    /** Human label, e.g. 'Order Blocks'. */
    public function label(): string;

    /**
     * @param array<int, array<string, mixed>> $rows Chronological OHLC(V) rows.
     * @return array<int, StructureEvent> Empty when nothing is found.
     */
    public function detect(array $rows): array;
}
