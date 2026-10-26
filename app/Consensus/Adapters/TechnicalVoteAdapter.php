<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use App\MarketOverview\SignalPreviewService;
use App\Services\IndicatorService;
use App\Services\MarketDataService;
use App\Services\SignalEngineService;
use Throwable;

/**
 * The system's own core indicator-based signal (App\Services\
 * SignalEngineService, the same engine the real Market Scanner uses) as one
 * consensus voter — weighted highest since it's the app's primary,
 * already-proven decision source, not a new opinion being asked for.
 */
final class TechnicalVoteAdapter implements ConsensusSourceInterface
{
    public const WEIGHT = 2.0;

    public function __construct(
        private IndicatorService $indicators = new IndicatorService(),
        private SignalEngineService $engine = new SignalEngineService(),
    ) {
    }

    public function vote(string $symbol, string $label): ConsensusVote
    {
        $raw = $this->evaluateRaw($symbol, $label);
        return $raw === null ? $this->unavailable() : $this->voteFromRaw($raw);
    }

    /** Lets the Consensus Engine build the vote from a raw result it already fetched (for trade-plan building), instead of fetching candles twice. */
    public function voteFromRaw(array $raw): ConsensusVote
    {
        $direction = match ($raw['action']) {
            'BUY'  => ConsensusVote::DIRECTION_BUY,
            'SELL' => ConsensusVote::DIRECTION_SELL,
            default => ConsensusVote::DIRECTION_NEUTRAL, // WAIT, AVOID
        };

        $reasons = implode('; ', array_slice($raw['reasons'], 0, 2));

        return new ConsensusVote(
            source: 'Technical',
            direction: $direction,
            confidence: $raw['confidence'],
            weight: EvidenceWeights::for('Technical', self::WEIGHT),
            reasoning: $reasons !== '' ? $reasons : 'Indicator-based signal from the core strategy engine.',
            available: true,
        );
    }

    /**
     * Exposed separately so the Consensus Engine can reuse the same fetch
     * for trade-plan building (entry price, ATR, hold-time window) instead
     * of fetching candles a second time just to get those fields.
     *
     * @return array{action:string, confidence:float, reasons:array<string>, entry_price:float, atr14:float, hold_hours_min:int, hold_hours_max:int}|null
     */
    public function evaluateRaw(string $symbol, string $label): ?array
    {
        try {
            $rows = $this->indicators->enrich((new MarketDataService())->fetchCandles($symbol));
            if (count($rows) < 2) {
                return null;
            }

            $sentimentSummary = SignalPreviewService::approximateSentimentSummary();
            $signal = $this->engine->evaluate($rows, $sentimentSummary);
            $latest = $rows[count($rows) - 1];

            return [
                'action'          => strtoupper((string) $signal['action']),
                'confidence'      => (float) $signal['confidence'],
                'reasons'         => (array) ($signal['reasons'] ?? []),
                'entry_price'     => (float) ($latest['close'] ?? 0.0),
                'atr14'           => (float) ($latest['atr14'] ?? 0.0),
                'hold_hours_min'  => (int) ($signal['hold_hours_min'] ?? 4),
                'hold_hours_max'  => (int) ($signal['hold_hours_max'] ?? 18),
            ];
        } catch (Throwable $e) {
            error_log("[TechnicalVoteAdapter] {$label} failed: " . $e->getMessage());
            return null;
        }
    }

    private function unavailable(): ConsensusVote
    {
        return new ConsensusVote('Technical', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, 'No usable candle data right now.', false);
    }
}
