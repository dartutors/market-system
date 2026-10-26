<?php
declare(strict_types=1);
namespace App\Risk;

use App\Config\Risk as RiskConfig;
use App\Models\SignalEvent;

/**
 * Nets directional exposure per currency across all open positions
 * (SignalEvent::openPositions() — BUY/SELL signals with no outcome recorded
 * yet, this system's only concept of "still open"). A BUY EUR/USD is +1 EUR
 * / -1 USD exposure; a SELL is the reverse. Warns when the net magnitude to
 * any one currency exceeds App\Config\Risk::maxExposurePerCurrency().
 */
final class ExposureTracker
{
    /**
     * @return array{open_position_count:int, net_exposure:array<string,int>, warnings:array<string>}
     */
    public function assess(): array
    {
        $openPositions = SignalEvent::openPositions();

        $netExposure = [];
        foreach ($openPositions as $position) {
            $vector = CurrencyPair::exposureVector((string) $position['market_label'], (string) $position['action']);
            foreach ($vector as $currency => $delta) {
                $netExposure[$currency] = ($netExposure[$currency] ?? 0) + $delta;
            }
        }

        $maxExposure = RiskConfig::maxExposurePerCurrency();
        $warnings    = [];

        foreach ($netExposure as $currency => $net) {
            if (abs($net) > $maxExposure) {
                $direction = $net > 0 ? 'long' : 'short';
                $warnings[] = "Net {$direction} exposure to {$currency} across open positions is {$net} units, "
                            . "above the configured maximum of {$maxExposure} — consider reducing before adding more {$currency} exposure.";
            }
        }

        return [
            'open_position_count' => count($openPositions),
            'net_exposure'         => $netExposure,
            'warnings'             => $warnings,
        ];
    }
}
