<?php
declare(strict_types=1);
namespace App\Risk;

/**
 * Pip *size* (a price-distance unit) — distinct from pip *value* (the dollar
 * value of one pip per lot, which App\Services\RiskCalculatorService already
 * handles for position sizing). Pip size depends only on the quote currency:
 * JPY-quoted pairs move in 0.01 increments; everything else in this system
 * (USD/EUR/GBP-quoted majors) moves in 0.0001 increments.
 */
final class PipMath
{
    private const JPY_PIP_SIZE     = 0.01;
    private const STANDARD_PIP_SIZE = 0.0001;

    public static function pipSize(string $pairLabel): float
    {
        $pair = CurrencyPair::parse($pairLabel);
        return $pair->quote === 'JPY' ? self::JPY_PIP_SIZE : self::STANDARD_PIP_SIZE;
    }

    public static function priceDistanceToPips(float $priceDistance, string $pairLabel): float
    {
        $size = self::pipSize($pairLabel);
        return $size > 0.0 ? abs($priceDistance) / $size : 0.0;
    }

    public static function pipsToPriceDistance(float $pips, string $pairLabel): float
    {
        return abs($pips) * self::pipSize($pairLabel);
    }
}
