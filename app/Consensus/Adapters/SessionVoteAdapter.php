<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Services\TradingSessionService;
use Throwable;

/**
 * Not a directional voter (a trading session can't itself mean BUY or
 * SELL) — a quality/context modifier. `confidence` here means "how
 * favorable is the current session," feeding MarketQualityScorer, not the
 * BUY/SELL tally. Session overlaps (e.g. London/New York) get the highest
 * favorability — genuinely the highest-liquidity windows.
 */
final class SessionVoteAdapter implements ConsensusSourceInterface
{
    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $service = new TradingSessionService();
            $current = $service->currentSession();
            $overlap = $service->isOverlap();

            $favorability = match (true) {
                $overlap => 90.0,
                $current === 'off' => 20.0,
                default => 65.0,
            };

            $note = $overlap
                ? ucfirst($current) . ' session — overlapping with another major session, highest liquidity window.'
                : ($current === 'off' ? 'Outside major session hours — thin liquidity.' : ucfirst($current) . ' session active.');

            return new ConsensusVote(
                source: 'Session',
                direction: ConsensusVote::DIRECTION_NEUTRAL,
                confidence: $favorability,
                weight: 0.0,
                reasoning: $note,
                available: true,
                isDirectional: false,
            );
        } catch (Throwable $e) {
            error_log("[SessionVoteAdapter] {$label} failed: " . $e->getMessage());
            return new ConsensusVote('Session', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0, 'Session data unavailable.', false, false);
        }
    }
}
