<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use App\Config\MarketIntelligence as MiConfig;

/**
 * Normalizes each pair's ATR into a price-independent volatility reading
 * (ATR as a % of price, so a JPY pair's larger absolute ATR doesn't read as
 * "more volatile" than a EUR/USD move of similar proportional size), then
 * averages across all configured pairs into one overall market reading.
 */
final class VolatilityIndexCalculator
{
    /**
     * @param array<string, PairSnapshot> $snapshots
     * @return array{overall_index:float, level:string, by_pair:array<string,array{atr_percent:float, level:string}>}
     */
    public static function calculate(array $snapshots): array
    {
        $byPair = [];
        $sum    = 0.0;

        foreach ($snapshots as $label => $snapshot) {
            $byPair[$label] = [
                'atr_percent' => $snapshot->atrPercent,
                'level'       => self::levelFor($snapshot->atrPercent),
            ];
            $sum += $snapshot->atrPercent;
        }

        $overall = count($snapshots) > 0 ? round($sum / count($snapshots), 4) : 0.0;

        return [
            'overall_index' => $overall,
            'level'         => self::levelFor($overall),
            'by_pair'       => $byPair,
        ];
    }

    public static function levelFor(float $atrPercent): string
    {
        return match (true) {
            $atrPercent >= MiConfig::volatilityHighThreshold() => 'high',
            $atrPercent < MiConfig::volatilityLowThreshold()   => 'low',
            default                                             => 'normal',
        };
    }
}
