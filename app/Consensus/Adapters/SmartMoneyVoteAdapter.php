<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use App\Services\IndicatorService;
use App\Services\MarketDataService;
use App\SmartMoney\StructureDetectorRegistry;
use Throwable;

/**
 * Runs every registered Smart Money Concepts detector (App\SmartMoney\
 * StructureDetectorRegistry — order blocks, FVGs, BOS/CHoCH, liquidity
 * sweeps, breaker blocks, accumulation/distribution, etc.) against the same
 * candles TechnicalVoteAdapter fetches, and derives a directional lean from
 * which bias (bullish/neutral/bearish) dominates the most recent structure
 * events — recency-weighted, since a Break-of-Structure from 200 bars ago
 * says nothing about right now.
 */
final class SmartMoneyVoteAdapter implements ConsensusSourceInterface
{
    public const WEIGHT = 1.25;
    private const RECENT_EVENTS_CONSIDERED = 12;

    public function __construct(private IndicatorService $indicators = new IndicatorService())
    {
    }

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $rows = $this->indicators->enrich((new MarketDataService())->fetchCandles($symbol));
            if (count($rows) < 20) {
                return $this->unavailable();
            }

            $allEvents = [];
            foreach (StructureDetectorRegistry::all() as $detector) {
                foreach ($detector->detect($rows) as $event) {
                    $allEvents[] = $event;
                }
            }

            if ($allEvents === []) {
                return new ConsensusVote(
                    source: 'SmartMoney',
                    direction: ConsensusVote::DIRECTION_NEUTRAL,
                    confidence: 0.0,
                    weight: EvidenceWeights::for('SmartMoney', self::WEIGHT),
                    reasoning: 'No institutional structure events detected in the recent window.',
                    available: true,
                );
            }

            // Most recent first by end index, then take the top N.
            usort($allEvents, static fn($a, $b) => $b->endIndex <=> $a->endIndex);
            $recent = array_slice($allEvents, 0, self::RECENT_EVENTS_CONSIDERED);

            $bullish = 0;
            $bearish = 0;
            $names = [];
            foreach ($recent as $event) {
                if ($event->direction === 'bullish') $bullish++;
                elseif ($event->direction === 'bearish') $bearish++;
                $names[$event->label] = true;
            }

            $total = $bullish + $bearish;
            if ($total === 0) {
                return new ConsensusVote('SmartMoney', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, 'Recent structure events carry no clear directional bias.', true);
            }

            $direction = ConsensusVote::DIRECTION_NEUTRAL;
            if ($bullish > $bearish) {
                $direction = ConsensusVote::DIRECTION_BUY;
            } elseif ($bearish > $bullish) {
                $direction = ConsensusVote::DIRECTION_SELL;
            }

            $dominant = max($bullish, $bearish);
            $confidence = min(100.0, ($dominant / $total) * 100);
            $namesList = implode(', ', array_slice(array_keys($names), 0, 3));

            return new ConsensusVote(
                source: 'SmartMoney',
                direction: $direction,
                confidence: $confidence,
                weight: EvidenceWeights::for('SmartMoney', self::WEIGHT),
                reasoning: "{$bullish} bullish vs {$bearish} bearish structure events among the last {$total} ({$namesList}).",
                available: true,
            );
        } catch (Throwable $e) {
            error_log("[SmartMoneyVoteAdapter] {$label} failed: " . $e->getMessage());
            return $this->unavailable();
        }
    }

    private function unavailable(): ConsensusVote
    {
        return new ConsensusVote('SmartMoney', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, 'Not enough candle data for structure detection.', false);
    }
}
