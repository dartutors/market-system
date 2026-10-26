<?php
declare(strict_types=1);
namespace App\Vision;

/**
 * The final BUY|SELL|WAIT|NO_TRADE call. Deterministic rule-based PHP, not
 * an AI call — App\Services\AiVisionSynthesisService only NARRATES this
 * already-computed result afterward, same "AI explains, never decides"
 * separation every other AI-summary service in this codebase already uses.
 * Consumes App\Vision\TopDownAnalysisEngine's output + App\Vision\
 * CompletenessScorer's output, both plain arrays.
 */
final class VisionDecisionEngine
{
    private const MIN_COMPLETENESS_FOR_TRADE     = 40.0;
    private const HIGH_RISK_CONFLICT_RATIO       = 0.5;
    private const MEDIUM_RISK_CONFLICT_RATIO     = 0.2;
    private const MEDIUM_RISK_CONFIDENCE_SPREAD  = 40.0;

    /**
     * @param array<string, mixed> $topDown Output of TopDownAnalysisEngine::analyze().
     * @param array<string, mixed> $completeness Output of CompletenessScorer::score().
     * @param array<string, array<string, mixed>> $byTimeframe The same per-timeframe reads fed to the top-down engine.
     */
    public function decide(array $topDown, array $completeness, array $byTimeframe): array
    {
        $totalTimeframes = \count($byTimeframe);
        $conflictCount   = \count($topDown['conflicting_timeframes']);
        $conflictRatio   = $totalTimeframes > 0 ? $conflictCount / $totalTimeframes : 0.0;

        $confidences = array_map(
            static fn(array $p): float => (float) ($p['recommendation']['confidence'] ?? 0.0),
            array_values($byTimeframe)
        );
        $confidenceSpread = !empty($confidences) ? max($confidences) - min($confidences) : 0.0;

        $riskLevel = match (true) {
            $topDown['potential_reversal'] || $conflictRatio > self::HIGH_RISK_CONFLICT_RATIO => 'high',
            $conflictRatio > self::MEDIUM_RISK_CONFLICT_RATIO || $confidenceSpread > self::MEDIUM_RISK_CONFIDENCE_SPREAD => 'medium',
            default => 'low',
        };

        // Gated by completeness/alignment FIRST: a technically clean setup
        // built on too little data (or too few aligned buckets to trust) is
        // not "excellent" — it's unrated. Otherwise "Trade quality: Excellent"
        // next to a NO_TRADE decision reads as a contradiction, even though
        // the two facts (setup shape vs. data sufficiency) are independent.
        $tradeQuality = match (true) {
            $completeness['percentage'] < self::MIN_COMPLETENESS_FOR_TRADE || $topDown['alignment'] === 'insufficient_data' => 'insufficient_data',
            $topDown['alignment'] === 'aligned' && $riskLevel === 'low' && $topDown['overall_confidence'] >= 70.0 => 'excellent',
            $topDown['alignment'] === 'aligned' && $riskLevel !== 'high' => 'good',
            $riskLevel === 'high' || $topDown['alignment'] === 'conflicted' => 'poor',
            default => 'fair',
        };

        [$decision, $confidence, $why] = $this->resolveDecision($topDown, $completeness, $riskLevel);

        return [
            'decision'               => $decision,
            'confidence'             => max(0.0, min(100.0, round($confidence, 1))),
            'risk_level'             => $riskLevel,
            'trade_quality'          => $tradeQuality,
            'why'                    => $why,
            'supporting_timeframes'  => $topDown['supporting_timeframes'],
            'conflicting_timeframes' => $topDown['conflicting_timeframes'],
        ];
    }

    /** @return array{0:string, 1:float, 2:array<string>} */
    private function resolveDecision(array $topDown, array $completeness, string $riskLevel): array
    {
        if ($completeness['percentage'] < self::MIN_COMPLETENESS_FOR_TRADE) {
            return [
                'NO_TRADE',
                $topDown['overall_confidence'] * ($completeness['percentage'] / 100),
                ["Only {$completeness['percentage']}% of the weighted timeframe stack was uploaded — not enough data for a directional call."],
            ];
        }

        if ($topDown['alignment'] === 'insufficient_data') {
            return ['NO_TRADE', 0.0, ['Not enough timeframe buckets have data to establish a top-down bias.']];
        }

        if ($topDown['potential_reversal'] || $topDown['alignment'] === 'conflicted') {
            $reason = $topDown['potential_reversal']
                ? 'Higher and lower timeframes point in opposite directions — wait for alignment before entering.'
                : 'Timeframe buckets disagree on direction — wait for alignment before entering.';
            return ['WAIT', $topDown['overall_confidence'] * 0.6, [$reason]];
        }

        if ($topDown['entry_opportunity'] && $riskLevel !== 'high') {
            $decision = $topDown['overall_trend'] === 'bullish' ? 'BUY' : 'SELL';
            $signal   = $topDown['lowest_timeframe_signal'];
            return [
                $decision,
                $topDown['overall_confidence'],
                ["{$signal['label']} confirms an entry aligned with the {$topDown['overall_trend']} bias across supporting timeframes."],
            ];
        }

        return ['WAIT', $topDown['overall_confidence'] * 0.7, ['Directional bias is established but no lower-timeframe entry trigger has confirmed yet.']];
    }
}
