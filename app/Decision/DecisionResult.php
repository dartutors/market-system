<?php
declare(strict_types=1);
namespace App\Decision;

use DateTimeImmutable;

/**
 * Structured facts only — no presentation text. Rendering human-readable
 * sentences is App\Explanation\ExplanationEngine's job (Phase 4 of the
 * foundation-enhancements sprint). `facts['branch']` records which decision
 * path was taken (calendar_blocked/no_direction/momentum_conflict/
 * low_confidence/aligned) so the Explanation Engine can render text without
 * re-deriving any decision logic.
 */
final class DecisionResult
{
    public function __construct(
        public readonly string $decision, // BUY|SELL|WAIT|NO_TRADE
        public readonly float $confidence,
        public readonly array $facts,
        public readonly array $conflictFlags,
        public readonly string $riskLevel,
        public readonly array $scoreBreakdown,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'decision'        => $this->decision,
            'confidence'      => $this->confidence,
            'facts'           => $this->facts,
            'conflict_flags'  => $this->conflictFlags,
            'risk_level'      => $this->riskLevel,
            'score_breakdown' => $this->scoreBreakdown,
            'generated_at'    => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
