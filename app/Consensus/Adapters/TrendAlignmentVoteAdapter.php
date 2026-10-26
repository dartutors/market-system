<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use App\Models\MultiTimeframeAnalysis;
use Throwable;

/**
 * Reads the most recent PERSISTED multi-timeframe analysis for this market
 * (App\Models\MultiTimeframeAnalysis) — never triggers a new one live (that
 * fetches 8 separate candle series synchronously with no cache, confirmed
 * far too slow for a background refresh to trigger automatically; it's a
 * manually-run analysis from /multi-timeframe). Stale beyond 48h = not
 * available, since higher-timeframe bias can meaningfully shift over days.
 */
final class TrendAlignmentVoteAdapter implements ConsensusSourceInterface
{
    public const WEIGHT = 1.25;
    private const FRESHNESS_HOURS = 48;

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $rows = MultiTimeframeAnalysis::latestForMarket($label, 1);
            $row = $rows[0] ?? null;
            if ($row === null) {
                return $this->unavailable();
            }

            $ageHours = (time() - strtotime((string) $row['created_at'])) / 3600;
            if ($ageHours > self::FRESHNESS_HOURS) {
                return $this->unavailable();
            }

            $bias = strtolower((string) $row['overall_bias']);
            $direction = match ($bias) {
                'bullish' => ConsensusVote::DIRECTION_BUY,
                'bearish' => ConsensusVote::DIRECTION_SELL,
                default   => ConsensusVote::DIRECTION_NEUTRAL,
            };

            return new ConsensusVote(
                source: 'TrendAlignment',
                direction: $direction,
                confidence: (float) $row['confidence'],
                weight: EvidenceWeights::for('TrendAlignment', self::WEIGHT),
                reasoning: ucfirst($bias) . ' multi-timeframe bias, ' . round($ageHours, 1) . 'h ago.',
                available: true,
            );
        } catch (Throwable $e) {
            error_log("[TrendAlignmentVoteAdapter] {$label} failed: " . $e->getMessage());
            return $this->unavailable();
        }
    }

    private function unavailable(): ConsensusVote
    {
        return new ConsensusVote('TrendAlignment', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, 'No recent multi-timeframe analysis for this market.', false);
    }
}
