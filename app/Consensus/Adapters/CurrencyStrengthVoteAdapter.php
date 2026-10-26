<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use App\MarketIntelligence\MarketIntelligenceEngine;
use Throwable;

/**
 * Reuses MarketIntelligenceEngine::buildCached() (the same shared 5-minute
 * cache the dashboard and /market-intelligence page already read) rather
 * than recomputing currency strength independently — avoids a duplicate
 * expensive candle fetch and keeps this number consistent with what's shown
 * elsewhere in the app at any given moment.
 */
final class CurrencyStrengthVoteAdapter implements ConsensusSourceInterface
{
    public const WEIGHT = 1.0;

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            [$base, $quote] = $this->legsOf($label);
            if ($base === null || $quote === null) {
                return $this->unavailable('Not a recognizable currency pair.');
            }

            $strength = (new MarketIntelligenceEngine())->buildCached()->currencyStrength;
            if (!isset($strength[$base], $strength[$quote])) {
                return $this->unavailable("Strength data not available for {$base}/{$quote}.");
            }

            $baseScore  = (float) $strength[$base];
            $quoteScore = (float) $strength[$quote];
            $diff = $baseScore - $quoteScore;

            $direction = ConsensusVote::DIRECTION_NEUTRAL;
            if ($diff > 0.05) {
                $direction = ConsensusVote::DIRECTION_BUY;
            } elseif ($diff < -0.05) {
                $direction = ConsensusVote::DIRECTION_SELL;
            }

            $confidence = min(100.0, abs($diff) * 40);

            return new ConsensusVote(
                source: 'CurrencyStrength',
                direction: $direction,
                confidence: $confidence,
                weight: EvidenceWeights::for('CurrencyStrength', self::WEIGHT),
                reasoning: sprintf('%s %+.2f%% vs %s %+.2f%%.', $base, $baseScore, $quote, $quoteScore),
                available: true,
            );
        } catch (Throwable $e) {
            error_log("[CurrencyStrengthVoteAdapter] {$label} failed: " . $e->getMessage());
            return $this->unavailable('Currency strength data unavailable right now.');
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    private function legsOf(string $label): array
    {
        $parts = explode('/', $label);
        if (\count($parts) !== 2) {
            return [null, null];
        }
        return [strtoupper(trim($parts[0])), strtoupper(trim($parts[1]))];
    }

    private function unavailable(string $reason): ConsensusVote
    {
        return new ConsensusVote('CurrencyStrength', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, $reason, false);
    }
}
