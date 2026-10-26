<?php
declare(strict_types=1);
namespace App\Vision;

/**
 * Combines N independent per-timeframe AI Vision reads (already-parsed
 * `vision_analysis.md` JSON, one per successfully-analyzed screenshot) into
 * one top-down technical assessment. Mirrors App\MultiTimeframe\
 * TrendAlignmentEngine's weighted-vote confluence math (same bias threshold,
 * same aligned/conflicting status classification, same human-readable
 * conflict-string style) but weighted by App\Vision\VisionTimeframeCatalog
 * (screenshot workspace weights) and additionally scaled by each
 * timeframe's OWN `recommendation.confidence` — a timeframe the AI wasn't
 * confident about should sway the vote less, which the real-candle-data
 * module has no equivalent of (it always trusts its own indicator math
 * fully). Returns plain arrays, same "engine functions over array data"
 * style as App\Backtesting\BacktestRunner — this is a compute-derived-facts
 * pipeline, not a domain-modeling problem.
 */
final class TopDownAnalysisEngine
{
    private const BIAS_THRESHOLD = 0.1;

    /**
     * @param array<string, array<string, mixed>> $byTimeframe Label => parsed vision_analysis.md JSON.
     */
    public function analyze(array $byTimeframe): array
    {
        [$overallTrend, $overallConfidence, $votes] = $this->weightedVote($byTimeframe);

        $buckets = [];
        foreach (VisionTimeframeCatalog::buckets() as $bucketName => $bucketLabels) {
            $bucketData = array_intersect_key($byTimeframe, array_flip($bucketLabels));
            if (empty($bucketData)) {
                $buckets[$bucketName] = ['bias' => null, 'confidence' => 0.0, 'labels' => []];
                continue;
            }
            [$bucketTrend, $bucketConfidence] = $this->weightedVote($bucketData, includeVotes: false);
            $buckets[$bucketName] = [
                'bias'       => $bucketTrend,
                'confidence' => $bucketConfidence,
                'labels'     => array_keys($bucketData),
            ];
        }

        $supporting = [];
        $conflicting = [];
        $conflicts = [];
        foreach ($votes as $label => $vote) {
            $status = $this->statusFor($vote['trend'], $overallTrend);
            if ($status === 'aligned') {
                $supporting[] = $label;
            } elseif ($status === 'conflicting') {
                $conflicting[] = $label;
                $conflicts[] = "{$label} shows a {$vote['trend']} trend while the overall bias is {$overallTrend}.";
            }
        }

        $long   = $buckets['long']['bias']   ?? null;
        $medium = $buckets['medium']['bias'] ?? null;
        $short  = $buckets['short']['bias']  ?? null;

        $bucketsWithData = array_filter([$long, $medium, $short], static fn($b) => $b !== null);
        $alignment = match (true) {
            \count($bucketsWithData) < 2                                                    => 'insufficient_data',
            \count(array_unique(array_filter($bucketsWithData, static fn($b) => $b !== 'neutral'))) <= 1 => 'aligned',
            default                                                                          => 'conflicted',
        };

        // A higher timeframe bucket conflicting with a nearer-term bucket is
        // the textbook "counter-trend move against the higher timeframe"
        // reversal signal — matches the worked example in the spec exactly
        // (long bullish, medium/H4 bearish, short flipping bullish again).
        $potentialReversal = $long !== null && $long !== 'neutral' && (
            ($medium !== null && $medium !== 'neutral' && $medium !== $long)
            || ($short !== null && $short !== 'neutral' && $short !== $long)
        );

        $momentum = $this->momentumFor($long, $short);

        $lowestSignal = $this->lowestTimeframeSignal($byTimeframe);
        $entryOpportunity = $lowestSignal !== null
            && \in_array($lowestSignal['decision'], ['BUY', 'SELL'], true)
            && $this->decisionDirection($lowestSignal['decision']) === $overallTrend
            && $overallTrend !== 'neutral';
        $exitOpportunity = $potentialReversal
            && $short !== null && $short !== 'neutral' && $short !== $overallTrend;

        return [
            'overall_trend'          => $overallTrend,
            'overall_confidence'     => $overallConfidence,
            'buckets'                => $buckets,
            'alignment'              => $alignment,
            'supporting_timeframes'  => $supporting,
            'conflicting_timeframes' => $conflicting,
            'conflicts'              => $conflicts,
            'momentum'               => $momentum,
            'potential_reversal'     => $potentialReversal,
            'entry_opportunity'      => $entryOpportunity,
            'exit_opportunity'       => $exitOpportunity,
            'lowest_timeframe_signal'=> $lowestSignal,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byTimeframe
     * @return array{0:string, 1:float, 2:array<string, array{trend:string, weight:float}>}|array{0:string, 1:float}
     */
    private function weightedVote(array $byTimeframe, bool $includeVotes = true): array
    {
        $weightedSum = 0.0;
        $usedWeight  = 0.0;
        $votes       = [];

        foreach ($byTimeframe as $label => $parsed) {
            $trend      = (string) ($parsed['trend'] ?? 'neutral');
            $confidence = (float) ($parsed['recommendation']['confidence'] ?? 50.0);
            $baseWeight = VisionTimeframeCatalog::weightFor($label);
            $weight     = $baseWeight * max(0.0, min(100.0, $confidence)) / 100.0;

            $vote = match ($trend) {
                'bullish' => 1.0,
                'bearish' => -1.0,
                default   => 0.0,
            };

            $weightedSum += $vote * $weight;
            $usedWeight  += $weight;
            $votes[$label] = ['trend' => $trend, 'weight' => $weight];
        }

        $normalized = $usedWeight > 0.0 ? $weightedSum / $usedWeight : 0.0;
        $trend = match (true) {
            $normalized > self::BIAS_THRESHOLD  => 'bullish',
            $normalized < -self::BIAS_THRESHOLD => 'bearish',
            default                              => 'neutral',
        };
        $confidence = round(min(100.0, abs($normalized) * 100), 1);

        return $includeVotes ? [$trend, $confidence, $votes] : [$trend, $confidence];
    }

    private function statusFor(string $trend, string $overallTrend): string
    {
        if ($overallTrend === 'neutral' || $trend === 'neutral') {
            return 'neutral';
        }
        return $trend === $overallTrend ? 'aligned' : 'conflicting';
    }

    private function momentumFor(?string $long, ?string $short): string
    {
        if ($long === null || $short === null) {
            return 'insufficient_data';
        }
        if ($long === 'neutral' || $short === 'neutral') {
            return 'neutral';
        }
        if ($long === $short) {
            return 'strengthening';
        }
        return 'diverging';
    }

    private function decisionDirection(string $decision): string
    {
        return match ($decision) {
            'BUY'  => 'bullish',
            'SELL' => 'bearish',
            default => 'neutral',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $byTimeframe
     * @return array{label:string, decision:string, confidence:float}|null
     */
    private function lowestTimeframeSignal(array $byTimeframe): ?array
    {
        // Shortest timeframe first — the entry-precision end of the stack.
        foreach (array_reverse(VisionTimeframeCatalog::labels()) as $label) {
            if (!isset($byTimeframe[$label])) {
                continue;
            }
            $decision = (string) ($byTimeframe[$label]['recommendation']['decision'] ?? 'NO_TRADE');
            if (\in_array($decision, ['BUY', 'SELL'], true)) {
                return [
                    'label'      => $label,
                    'decision'   => $decision,
                    'confidence' => (float) ($byTimeframe[$label]['recommendation']['confidence'] ?? 0.0),
                ];
            }
        }
        return null;
    }
}
