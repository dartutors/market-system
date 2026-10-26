<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

use DateTimeImmutable;

/**
 * Phase 2.3 deliverable: composes trend alignment, pullback identification,
 * and entry confirmation across all 8 timeframes into one hierarchical
 * recommendation — the "stronger, more reliable trade recommendation based
 * on higher-timeframe context" called for by the phase brief.
 *
 * Deliberately separate from App\Decision\DecisionEngine: that engine drives
 * the automatic cron scan (single-timeframe MarketSnapshot, news/calendar/
 * backtest factors) and stays untouched here. This engine only ever runs
 * on-demand behind the MULTI_TIMEFRAME feature flag — see
 * MultiTimeframeModule's doc comment.
 */
final class MultiTimeframeDecisionEngine
{
    public function __construct(
        private TrendAlignmentEngine $alignmentEngine = new TrendAlignmentEngine(),
        private PullbackDetector $pullbackDetector = new PullbackDetector(),
        private EntryConfirmationEngine $entryEngine = new EntryConfirmationEngine(),
        private MultiTimeframeAiSummaryService $aiSummaryService = new MultiTimeframeAiSummaryService(),
    ) {
    }

    /** @param array<string, TimeframeAnalysis> $byTimeframe */
    public function decide(string $symbol, string $marketLabel, array $byTimeframe): MultiTimeframeDecision
    {
        $alignment = $this->alignmentEngine->analyze($symbol, $byTimeframe);
        $pullbacks = $this->pullbackDetector->detect($alignment, $byTimeframe);
        $entry     = $this->entryEngine->confirm($alignment, $pullbacks, $byTimeframe);
        $aiSummary = $this->aiSummaryService->summarize($marketLabel, $alignment, $pullbacks, $entry);

        [$recommendation, $recommendationConfidence] = $this->recommend($alignment, $entry);

        return new MultiTimeframeDecision(
            symbol: $symbol,
            alignment: $alignment,
            pullbacks: $pullbacks,
            entry: $entry,
            aiSummary: $aiSummary,
            recommendation: $recommendation,
            recommendationConfidence: $recommendationConfidence,
            generatedAt: new DateTimeImmutable('now'),
        );
    }

    /** @return array{0:string, 1:float} */
    private function recommend(MultiTimeframeResult $alignment, EntryConfirmationResult $entry): array
    {
        if ($alignment->overallBias === 'neutral') {
            return ['NO_TRADE', min($alignment->confidence, 40.0)];
        }

        if ($entry->confirmed) {
            $direction = $alignment->overallBias === 'bullish' ? 'BUY' : 'SELL';
            return [$direction, $alignment->confidence];
        }

        if ($entry->state === 'pullback_only') {
            return ['WATCH', min($alignment->confidence, 60.0)];
        }

        return ['WAIT', min($alignment->confidence, 45.0)];
    }
}
