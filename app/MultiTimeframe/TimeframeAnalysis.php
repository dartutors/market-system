<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

use DateTimeImmutable;

final class TimeframeAnalysis
{
    public function __construct(
        public readonly string $label,
        public readonly string $interval,
        public readonly string $range,
        public readonly string $trend,
        public readonly string $momentum,
        public readonly float $close,
        public readonly float $sma20,
        public readonly float $sma50,
        public readonly float $rsi14,
        public readonly float $macdHist,
        public readonly float $atr14,
        public readonly int $barsUsed,
        public readonly DateTimeImmutable $fetchedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'label'      => $this->label,
            'interval'   => $this->interval,
            'range'      => $this->range,
            'trend'      => $this->trend,
            'momentum'   => $this->momentum,
            'close'      => $this->close,
            'sma20'      => $this->sma20,
            'sma50'      => $this->sma50,
            'rsi14'      => $this->rsi14,
            'macd_hist'  => $this->macdHist,
            'atr14'      => $this->atr14,
            'bars_used'  => $this->barsUsed,
            'fetched_at' => $this->fetchedAt->format('Y-m-d H:i:s'),
        ];
    }
}
