<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use App\MarketOverview\SignalPreviewService;
use Throwable;

/**
 * Reuses the same polarity×impact sentiment average
 * SignalPreviewService::approximateSentimentSummary() already computes from
 * persisted news_items (no new sentiment logic invented). A soft, low-weight
 * directional lean, not a primary voter — this is context corroborating or
 * questioning the technical/AI calls, not a standalone trading signal.
 */
final class NewsVoteAdapter implements ConsensusSourceInterface
{
    public const WEIGHT = 0.75;
    private const THRESHOLD = 0.1;

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $summary = SignalPreviewService::approximateSentimentSummary();
            $score = (float) $summary['score'];

            $direction = ConsensusVote::DIRECTION_NEUTRAL;
            if ($score > self::THRESHOLD) {
                $direction = ConsensusVote::DIRECTION_BUY;
            } elseif ($score < -self::THRESHOLD) {
                $direction = ConsensusVote::DIRECTION_SELL;
            }

            $label2 = $direction === ConsensusVote::DIRECTION_BUY ? 'Bullish' : ($direction === ConsensusVote::DIRECTION_SELL ? 'Bearish' : 'Neutral');
            $confidence = min(100.0, abs($score) * 200); // modest scale, never a high-confidence standalone signal

            return new ConsensusVote(
                source: 'News',
                direction: $direction,
                confidence: $confidence,
                weight: EvidenceWeights::for('News', self::WEIGHT),
                reasoning: "{$label2} (sentiment score " . round($score, 3) . ' from recent headlines).',
                available: true,
            );
        } catch (Throwable $e) {
            error_log("[NewsVoteAdapter] {$label} failed: " . $e->getMessage());
            return new ConsensusVote('News', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, 'News sentiment unavailable right now.', false);
        }
    }
}
