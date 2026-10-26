<?php
declare(strict_types=1);
namespace App\Risk;

/**
 * Suggests a stop-loss price from ATR (volatility-adjusted — the same atr_14
 * field App\Services\IndicatorService already computes on every enriched
 * candle row), or converts a manually chosen stop distance in pips into a
 * price. Direction-aware: a BUY's stop sits below entry, a SELL's sits above.
 */
final class StopLossCalculator
{
    /**
     * @return array{stop_price:float, stop_distance:float, stop_pips:float, atr_multiple:float}
     */
    public function fromAtr(float $entry, string $direction, float $atr14, string $pairLabel, ?float $atrMultiple = null): array
    {
        $multiple = $atrMultiple ?? \App\Config\Risk::defaultAtrStopMultiple();
        $distance = max(0.0, $atr14) * $multiple;

        $stopPrice = strtoupper(trim($direction)) === 'SELL'
            ? $entry + $distance
            : $entry - $distance;

        return [
            'stop_price'    => round($stopPrice, 6),
            'stop_distance' => round($distance, 6),
            'stop_pips'     => round(PipMath::priceDistanceToPips($distance, $pairLabel), 1),
            'atr_multiple'  => $multiple,
        ];
    }

    /**
     * @return array{stop_price:float, stop_distance:float, stop_pips:float}
     */
    public function fromPips(float $entry, string $direction, float $stopPips, string $pairLabel): array
    {
        $distance = PipMath::pipsToPriceDistance($stopPips, $pairLabel);

        $stopPrice = strtoupper(trim($direction)) === 'SELL'
            ? $entry + $distance
            : $entry - $distance;

        return [
            'stop_price'    => round($stopPrice, 6),
            'stop_distance' => round($distance, 6),
            'stop_pips'     => round(abs($stopPips), 1),
        ];
    }
}
