<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\MarketIntelligence\MarketIntelligenceEngine;
use Throwable;

/**
 * Not a directional voter — high volatility doesn't imply a direction, it's
 * a risk/quality modifier. Reuses the same shared cached snapshot as
 * CurrencyStrengthVoteAdapter (MarketIntelligenceEngine::buildCached()).
 */
final class VolatilityVoteAdapter implements ConsensusSourceInterface
{
    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $volatility = (new MarketIntelligenceEngine())->buildCached()->volatilityIndex;
            $byPair = $volatility['by_pair'] ?? [];
            $pairData = $byPair[$label] ?? null;

            if ($pairData === null) {
                return new ConsensusVote('Volatility', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0, 'Volatility data not available for this pair.', false, false);
            }

            $level = (string) $pairData['level'];
            $favorability = match ($level) {
                'high' => 30.0,   // elevated risk, not necessarily bad but flagged
                'low'  => 55.0,   // too quiet can mean poor follow-through too
                default => 75.0,  // normal is the sweet spot
            };

            return new ConsensusVote(
                source: 'Volatility',
                direction: ConsensusVote::DIRECTION_NEUTRAL,
                confidence: $favorability,
                weight: 0.0,
                reasoning: ucfirst($level) . ' volatility (ATR ' . round((float) $pairData['atr_percent'], 3) . '%).',
                available: true,
                isDirectional: false,
            );
        } catch (Throwable $e) {
            error_log("[VolatilityVoteAdapter] {$label} failed: " . $e->getMessage());
            return new ConsensusVote('Volatility', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0, 'Volatility data unavailable.', false, false);
        }
    }
}
