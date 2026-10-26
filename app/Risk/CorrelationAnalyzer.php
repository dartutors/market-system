<?php
declare(strict_types=1);
namespace App\Risk;

use App\Models\SignalEvent;

/**
 * Warns when a proposed new trade would compound exposure already carried
 * by an open position (SignalEvent::openPositions()) through a shared
 * currency — e.g. an open BUY EUR/USD (long EUR, short USD) plus a proposed
 * BUY GBP/USD (long GBP, short USD) both add to the same USD-short exposure,
 * even though the two pairs are nominally "different trades." Only the
 * compounding case is worth flagging — a shared currency in opposite
 * directions is a partial hedge, not a risk pile-up, so it stays silent.
 */
final class CorrelationAnalyzer
{
    /** @return array<string> */
    public function warningsFor(string $newMarketLabel, string $newDirection): array
    {
        $newVector = CurrencyPair::exposureVector($newMarketLabel, $newDirection);
        $warnings  = [];

        foreach (SignalEvent::openPositions() as $position) {
            $openLabel = (string) $position['market_label'];
            $openAction = (string) $position['action'];

            if ($openLabel === $newMarketLabel) {
                continue; // Same pair — that's a position-size question, not a correlation question.
            }

            $openVector = CurrencyPair::exposureVector($openLabel, $openAction);

            foreach ($newVector as $currency => $newSign) {
                $openSign = $openVector[$currency] ?? 0;
                if ($openSign !== 0 && $openSign === $newSign) {
                    $direction = $newSign > 0 ? 'long' : 'short';
                    $warnings[] = "You already have an open {$openAction} {$openLabel} position, which is also net {$direction} {$currency}. "
                                . "A {$newDirection} on {$newMarketLabel} adds to that same {$currency} exposure rather than diversifying it.";
                }
            }
        }

        return array_values(array_unique($warnings));
    }
}
