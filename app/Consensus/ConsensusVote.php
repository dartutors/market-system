<?php
declare(strict_types=1);
namespace App\Consensus;

/**
 * One source's opinion, normalized to a common shape so ConsensusScorer can
 * compare Claude's text opinion against the Technical engine's indicator
 * signal against Vision's chart read without caring how each was produced.
 */
final class ConsensusVote
{
    public const DIRECTION_BUY     = 'BUY';
    public const DIRECTION_SELL    = 'SELL';
    public const DIRECTION_NEUTRAL = 'NEUTRAL';

    public function __construct(
        public readonly string $source,       // 'Technical'|'Claude'|'OpenAI'|'Vision'|'News'|'Calendar'|'CurrencyStrength'|'Session'|'Volatility'|'TrendAlignment'
        public readonly string $direction,     // BUY|SELL|NEUTRAL — NEUTRAL covers WAIT/safe/unavailable-but-non-blocking
        public readonly float $confidence,     // 0-100
        public readonly float $weight,         // relative influence on the weighted tally; 0 for non-directional context sources
        public readonly string $reasoning,
        public readonly bool $available,       // false = source had nothing to say (e.g. no recent Vision analysis) — excluded from agreement % denominator
        public readonly bool $isDirectional = true, // false = context/quality modifier only (Session, Volatility) — never counted in the BUY/SELL tally
        public readonly bool $isBlocking = false,    // true = a hard veto (Calendar high-impact window) that forces the overall decision to WAIT
    ) {
    }

    public function toArray(): array
    {
        return [
            'source'         => $this->source,
            'direction'      => $this->direction,
            'confidence'     => round($this->confidence, 1),
            'weight'         => $this->weight,
            'reasoning'      => $this->reasoning,
            'available'      => $this->available,
            'is_directional' => $this->isDirectional,
            'is_blocking'    => $this->isBlocking,
        ];
    }
}
