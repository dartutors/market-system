<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use App\Models\VisionSession;
use Throwable;

/**
 * Reads the most recent PERSISTED AI Vision session for this market (within
 * 24h) — never triggers a new Vision analysis (that requires the user to
 * upload chart images; it's not something a background consensus refresh
 * can do on its own). No recent analysis = not available, excluded from the
 * agreement % denominator, not treated as a silent "no opinion" vote.
 */
final class VisionVoteAdapter implements ConsensusSourceInterface
{
    public const WEIGHT = 1.5;
    private const FRESHNESS_HOURS = 24;

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $session = VisionSession::latestForMarket($label, self::FRESHNESS_HOURS);
            if ($session === null) {
                return $this->unavailable();
            }

            $decision = strtoupper((string) $session['decision']);
            $direction = match ($decision) {
                'BUY'  => ConsensusVote::DIRECTION_BUY,
                'SELL' => ConsensusVote::DIRECTION_SELL,
                default => ConsensusVote::DIRECTION_NEUTRAL, // WAIT, NO_TRADE
            };

            return new ConsensusVote(
                source: 'Vision',
                direction: $direction,
                confidence: (float) $session['confidence'],
                weight: EvidenceWeights::for('Vision', self::WEIGHT),
                reasoning: "Multi-timeframe chart analysis ({$session['risk_level']} risk), " . $this->ago($session['created_at']),
                available: true,
            );
        } catch (Throwable $e) {
            error_log("[VisionVoteAdapter] {$label} failed: " . $e->getMessage());
            return $this->unavailable();
        }
    }

    private function ago(string $createdAt): string
    {
        $hours = (time() - strtotime($createdAt)) / 3600;
        return $hours < 1 ? round($hours * 60) . 'm ago' : round($hours, 1) . 'h ago';
    }

    private function unavailable(): ConsensusVote
    {
        return new ConsensusVote('Vision', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, 'No chart analysis uploaded for this market in the last 24 hours.', false);
    }
}
