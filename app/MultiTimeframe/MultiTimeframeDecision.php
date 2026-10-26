<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

use DateTimeImmutable;

final class MultiTimeframeDecision
{
    /**
     * @param array<string, PullbackAssessment> $pullbacks
     * @param array{provider:string, model:string, agrees_with_technical:bool, narrative:string, risk_note:string} $aiSummary
     */
    public function __construct(
        public readonly string $symbol,
        public readonly MultiTimeframeResult $alignment,
        public readonly array $pullbacks,
        public readonly EntryConfirmationResult $entry,
        public readonly array $aiSummary,
        public readonly string $recommendation, // BUY|SELL|WATCH|WAIT|NO_TRADE
        public readonly float $recommendationConfidence,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol'                     => $this->symbol,
            'alignment'                  => $this->alignment->toArray(),
            'pullbacks'                  => array_map(
                static fn(PullbackAssessment $p): array => $p->toArray(),
                $this->pullbacks
            ),
            'entry'                      => $this->entry->toArray(),
            'ai_summary'                 => $this->aiSummary,
            'recommendation'             => $this->recommendation,
            'recommendation_confidence'  => $this->recommendationConfidence,
            'generated_at'               => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
