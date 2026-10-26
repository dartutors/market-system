<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

/**
 * One configured pair's Daily-timeframe snapshot — the shared input every
 * other Market Intelligence calculator reads from, so the underlying candle
 * data is only fetched and enriched once per page load
 * (see PairSnapshotFetcher).
 */
final class PairSnapshot
{
    /** @param array<float> $closeSeries Trailing daily closes, oldest first — used for correlation. */
    public function __construct(
        public readonly string $label,
        public readonly string $symbol,
        public readonly float $close,
        public readonly float $dailyReturnPct,
        public readonly float $periodReturnPct,
        public readonly float $sma20,
        public readonly float $sma50,
        public readonly float $rsi14,
        public readonly float $atr14,
        public readonly float $atrPercent,
        public readonly float $adx14,
        public readonly string $trend,
        public readonly string $momentum,
        public readonly array $closeSeries,
    ) {
    }

    public function toArray(): array
    {
        return [
            'label'              => $this->label,
            'symbol'             => $this->symbol,
            'close'              => $this->close,
            'daily_return_pct'   => $this->dailyReturnPct,
            'period_return_pct'  => $this->periodReturnPct,
            'sma20'              => $this->sma20,
            'sma50'              => $this->sma50,
            'rsi14'              => $this->rsi14,
            'atr14'              => $this->atr14,
            'atr_percent'        => $this->atrPercent,
            'adx14'              => $this->adx14,
            'trend'              => $this->trend,
            'momentum'           => $this->momentum,
        ];
    }
}
