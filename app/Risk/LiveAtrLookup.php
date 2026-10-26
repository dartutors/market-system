<?php
declare(strict_types=1);
namespace App\Risk;

use App\MarketData\Manager\MarketDataManager;
use App\Services\IndicatorService;
use RuntimeException;

/**
 * Live Daily-timeframe ATR fetch for a configured market label, shared by
 * RiskManagementController (manual stop omitted) and VisionController (an
 * AI Vision recommendation with no usable support/resistance level nearby).
 *
 * Phase 2.2A: fetches via App\MarketData\Manager\MarketDataManager (Deriv ->
 * Yahoo Finance -> cache) instead of talking to Yahoo Finance directly — no
 * new HTTP client, no duplicated indicator math.
 */
final class LiveAtrLookup
{
    private const MIN_BARS = 55;

    public function __construct(private MarketDataManager $manager = new MarketDataManager())
    {
    }

    public function forMarketLabel(string $marketLabel): float
    {
        $symbol = null;
        foreach (configured_markets() as $m) {
            if ($m['label'] === $marketLabel) {
                $symbol = $m['symbol'];
                break;
            }
        }

        if ($symbol === null) {
            throw new RuntimeException("No market symbol found for {$marketLabel} — provide a stop price or stop distance in pips instead.");
        }

        $rows = $this->manager->fetchCandles($symbol, '1d', '2y');

        if (count($rows) < self::MIN_BARS) {
            throw new RuntimeException('Not enough live candle data to auto-suggest a stop — provide a stop price or stop distance in pips instead.');
        }

        $enriched = (new IndicatorService())->enrich($rows);
        if (empty($enriched)) {
            throw new RuntimeException('Could not compute ATR right now — provide a stop price or stop distance in pips instead.');
        }

        return (float) $enriched[count($enriched) - 1]['atr_14'];
    }
}
