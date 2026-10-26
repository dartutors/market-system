<?php
declare(strict_types=1);
namespace App\SmartMoney\Models;

/**
 * One detected institutional-structure event/zone (Order Block, Fair Value
 * Gap, Liquidity Pool, Equal Highs/Lows, BOS, CHoCH, Premium/Discount Zone,
 * Liquidity Sweep, or Mitigation Block). Deliberately its own model rather
 * than reusing App\Patterns\Models\PatternMatch — that model is scoped to
 * Phase 2.9's chart-geometry patterns (a single start/end span); this one
 * additionally carries an optional zone (zoneHigh/zoneLow, for Order
 * Blocks/FVGs/Liquidity Pools/Premium-Discount Zones which are PRICE RANGES,
 * not just point events) and a mitigated flag (has price since traded back
 * through this zone, invalidating it as "fresh").
 */
final class StructureEvent
{
    public function __construct(
        public readonly string $type,      // machine key, e.g. 'order_block'
        public readonly string $label,     // human label, e.g. 'Bullish Order Block'
        public readonly string $direction, // 'bullish' | 'bearish' | 'neutral'
        public readonly float $confidence, // 0-100
        public readonly int $startIndex,
        public readonly int $endIndex,
        public readonly int $startTimestamp,
        public readonly int $endTimestamp,
        public readonly string $description,
        public readonly ?float $zoneHigh = null,
        public readonly ?float $zoneLow = null,
        public readonly bool $mitigated = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type'            => $this->type,
            'label'           => $this->label,
            'direction'       => $this->direction,
            'confidence'      => $this->confidence,
            'start_index'     => $this->startIndex,
            'end_index'       => $this->endIndex,
            'start_timestamp' => $this->startTimestamp,
            'end_timestamp'   => $this->endTimestamp,
            'description'     => $this->description,
            'zone_high'       => $this->zoneHigh,
            'zone_low'        => $this->zoneLow,
            'mitigated'       => $this->mitigated,
        ];
    }
}
