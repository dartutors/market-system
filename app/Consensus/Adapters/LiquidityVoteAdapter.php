<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Services\TradingSessionService;
use Throwable;

/**
 * Not a directional voter — liquidity doesn't imply BUY or SELL, it's a
 * tradability/risk modifier (thin liquidity means wider spreads and less
 * reliable price action, real reasons to distrust a signal regardless of
 * its direction). Deliberately separate from VolatilityVoteAdapter: high
 * volatility and low liquidity are different, sometimes opposite,
 * conditions (a liquidity gap can itself CAUSE a volatility spike). Reuses
 * the same session-tier reasoning already shipped in the dashboard's Market
 * Clock widget, now server-side so ConflictDetector can act on it (e.g.
 * flag "low liquidity during recommended entry").
 */
final class LiquidityVoteAdapter implements ConsensusSourceInterface
{
    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $service = new TradingSessionService();
            $session = $service->currentSession();
            $overlap = $service->isOverlap();

            [$tierLabel, $favorability] = match (true) {
                $overlap => ['Very High', 95.0],
                $session === 'london' || $session === 'new_york' => ['High', 80.0],
                $session === 'tokyo' => ['Medium', 55.0],
                $session === 'sydney' => ['Low', 35.0],
                default => ['Very Low', 15.0],
            };

            return new ConsensusVote(
                source: 'Liquidity',
                direction: ConsensusVote::DIRECTION_NEUTRAL,
                confidence: $favorability,
                weight: 0.0,
                reasoning: "{$tierLabel} liquidity (" . ($session === 'off' ? 'outside major sessions' : ucfirst(str_replace('_', ' ', $session)) . ' session') . ($overlap ? ', session overlap' : '') . ').',
                available: true,
                isDirectional: false,
            );
        } catch (Throwable $e) {
            error_log("[LiquidityVoteAdapter] {$label} failed: " . $e->getMessage());
            return new ConsensusVote('Liquidity', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0, 'Liquidity data unavailable.', false, false);
        }
    }
}
