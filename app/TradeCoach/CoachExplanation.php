<?php
declare(strict_types=1);
namespace App\TradeCoach;

use DateTimeImmutable;

final class CoachExplanation
{
    /**
     * @param array<string> $reasoning Existing reasoning sentences from the decision, explained (not recomputed).
     * @param array<string> $conflicts Existing conflict sentences, if any.
     * @param array<string> $educationalTips
     * @param array<string> $commonMistakes
     * @param array{provider:string, model:string, narrative:string, risk_note:string}|null $aiNarrative
     */
    public function __construct(
        public readonly string $marketLabel,
        public readonly string $decision,
        public readonly float $confidence,
        public readonly string $riskLevel,
        public readonly string $mode,
        public readonly array $reasoning,
        public readonly array $conflicts,
        public readonly string $headline,
        public readonly string $riskExplanation,
        public readonly array $educationalTips,
        public readonly array $commonMistakes,
        public readonly string $psychologyGuidance,
        public readonly ?array $aiNarrative,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'market_label'        => $this->marketLabel,
            'decision'             => $this->decision,
            'confidence'           => $this->confidence,
            'risk_level'           => $this->riskLevel,
            'mode'                 => $this->mode,
            'reasoning'            => $this->reasoning,
            'conflicts'            => $this->conflicts,
            'headline'             => $this->headline,
            'risk_explanation'     => $this->riskExplanation,
            'educational_tips'     => $this->educationalTips,
            'common_mistakes'      => $this->commonMistakes,
            'psychology_guidance'  => $this->psychologyGuidance,
            'ai_narrative'         => $this->aiNarrative,
            'generated_at'         => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
