<?php
declare(strict_types=1);
namespace App\Explanation;

final class ExplanationResult
{
    public function __construct(
        public readonly array $reasoning,
        public readonly array $conflicts,
        public readonly string $tradeExplanation,
        public readonly string $confidenceExplanation,
        public readonly string $riskExplanation,
        public readonly string $conflictingSignalExplanation,
        public readonly string $educationalExplanation,
    ) {
    }

    public function toArray(): array
    {
        return [
            'reasoning'                      => $this->reasoning,
            'conflicts'                      => $this->conflicts,
            'trade_explanation'              => $this->tradeExplanation,
            'confidence_explanation'         => $this->confidenceExplanation,
            'risk_explanation'               => $this->riskExplanation,
            'conflicting_signal_explanation' => $this->conflictingSignalExplanation,
            'educational_explanation'        => $this->educationalExplanation,
        ];
    }
}
