<?php
declare(strict_types=1);
namespace App\Patterns\Models;

/**
 * A local extremum ("fractal") in a price series — the foundational
 * primitive most chart patterns reduce to (Double Top/Bottom, Head &
 * Shoulders, Triangle, Wedge, Channel, Cup & Handle all boil down to
 * constraints over a sequence of these). Nothing like this existed
 * anywhere in the codebase before Phase 2.9 (confirmed by research).
 */
final class SwingPoint
{
    public function __construct(
        public readonly int $index,
        public readonly int $timestamp,
        public readonly float $price,
        public readonly string $type, // 'high' | 'low'
    ) {
    }
}
