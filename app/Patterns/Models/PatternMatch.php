<?php
declare(strict_types=1);
namespace App\Patterns\Models;

/**
 * A single detected chart pattern instance. Confidence is a detector-local
 * 0-100 score reflecting how cleanly the geometric rules were met (tight
 * tolerance matches score higher than loose ones) — it is not calibrated
 * against historical win-rate the way Phase 2.8's confidence calibration is;
 * that remains a possible future integration (feed pattern outcomes into
 * App\Learning\PatternLearningService) rather than something this phase
 * wires up.
 */
final class PatternMatch
{
    /** @param array<int, float> $keyLevels Support/resistance/neckline levels relevant to this pattern, for display or downstream risk sizing. */
    public function __construct(
        public readonly string $pattern,   // machine key, e.g. 'double_top'
        public readonly string $label,     // human name, e.g. 'Double Top'
        public readonly string $direction, // 'bullish' | 'bearish' | 'neutral'
        public readonly float $confidence, // 0-100
        public readonly int $startIndex,
        public readonly int $endIndex,
        public readonly int $startTimestamp,
        public readonly int $endTimestamp,
        public readonly string $description,
        public readonly array $keyLevels = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'pattern'         => $this->pattern,
            'label'           => $this->label,
            'direction'       => $this->direction,
            'confidence'      => $this->confidence,
            'start_index'     => $this->startIndex,
            'end_index'       => $this->endIndex,
            'start_timestamp' => $this->startTimestamp,
            'end_timestamp'   => $this->endTimestamp,
            'description'     => $this->description,
            'key_levels'      => $this->keyLevels,
        ];
    }
}
