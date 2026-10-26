<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use App\Risk\CurrencyPair;

/**
 * Attributes each configured pair's daily return to its base (+) and quote
 * (-) currency (reusing App\Risk\CurrencyPair's parsing, already built for
 * exposure tracking in Phase 2.5), then averages per currency across every
 * pair it appears in. The same output feeds both the ranked "Currency
 * Strength Meter" list and the "Sector Heatmap" grid — same underlying
 * data, two presentations (this system has no real equity-style sectors;
 * currency blocs are the honest forex equivalent — see PairSnapshotFetcher's
 * doc comment for the rest of that reasoning).
 */
final class CurrencyStrengthCalculator
{
    /**
     * @param array<string, PairSnapshot> $snapshots
     * @return array<string, float> currency => average return %, sorted strongest first.
     */
    public static function calculate(array $snapshots): array
    {
        $sums   = [];
        $counts = [];

        foreach ($snapshots as $label => $snapshot) {
            $vector = CurrencyPair::exposureVector($label, 'BUY'); // +1 base / -1 quote — direction-neutral decomposition
            foreach ($vector as $currency => $sign) {
                $sums[$currency]   = ($sums[$currency]   ?? 0.0) + ($sign * $snapshot->dailyReturnPct);
                $counts[$currency] = ($counts[$currency] ?? 0)   + 1;
            }
        }

        $strength = [];
        foreach ($sums as $currency => $sum) {
            $strength[$currency] = round($sum / max(1, $counts[$currency]), 4);
        }

        arsort($strength);
        return $strength;
    }
}
