<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

/**
 * Ranks configured pairs by trend strength: ADX14 (a directionless
 * trend-strength magnitude, 0-100) signed by trend direction, so a
 * strongly-trending bearish pair scores as negatively as a strongly-trending
 * bullish pair scores positively. Sorted descending, this single list serves
 * both "strength ranking" (read from the top) and "weakness ranking" (read
 * from the bottom) — they are the same spectrum viewed from either end.
 */
final class PairStrengthRanker
{
    /**
     * @param array<string, PairSnapshot> $snapshots
     * @return array<int, array{label:string, signed_score:float, adx14:float, trend:string}>
     */
    public static function rank(array $snapshots): array
    {
        $ranked = [];

        foreach ($snapshots as $label => $snapshot) {
            $sign = match ($snapshot->trend) {
                'bullish' => 1.0,
                'bearish' => -1.0,
                default   => 0.0,
            };

            $ranked[] = [
                'label'        => $label,
                'signed_score' => round($snapshot->adx14 * $sign, 2),
                'adx14'        => round($snapshot->adx14, 2),
                'trend'        => $snapshot->trend,
            ];
        }

        usort($ranked, static fn(array $a, array $b): int => $b['signed_score'] <=> $a['signed_score']);

        return $ranked;
    }
}
