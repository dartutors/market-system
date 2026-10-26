<?php
declare(strict_types=1);
namespace App\Backtesting\Models;

/**
 * One simulated trade from App\Backtesting\BacktestRunner. Same
 * "readonly value object with toArray()" style as App\Patterns\Models\
 * PatternMatch and App\SmartMoney\Models\StructureEvent — kept distinct
 * from both since this describes a BACKTEST SIMULATION outcome (an R-multiple
 * P&L against an ATR target/stop), not a chart-geometry match or an
 * institutional-structure zone.
 */
final class TradeResult
{
    public function __construct(
        public readonly int $index,
        public readonly int $timestamp,
        public readonly string $action,      // 'BUY' | 'SELL'
        public readonly float $entryPrice,
        public readonly float $atr,
        public readonly float $confidence,
        public readonly string $outcome,     // 'win' | 'loss' | 'breakeven' | 'unknown'
        public readonly float $pl,           // R-multiple: win=+2, loss=-1, else 0
        public readonly array $reasons = [],
        public readonly ?int $resolvedAtBarsForward = null, // how many forward candles until target/stop was hit — null for 'unknown'/'breakeven' (timed out without hitting either)
    ) {
    }

    public function toArray(): array
    {
        return [
            'index'       => $this->index,
            'timestamp'   => $this->timestamp,
            'action'      => $this->action,
            'entry_price' => round($this->entryPrice, 5),
            'atr'         => round($this->atr, 5),
            'confidence'  => round($this->confidence, 1),
            'outcome'     => $this->outcome,
            'pl'          => $this->pl,
            'reasons'     => $this->reasons,
            'resolved_at_bars_forward' => $this->resolvedAtBarsForward,
        ];
    }
}
