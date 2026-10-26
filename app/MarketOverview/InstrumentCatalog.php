<?php
declare(strict_types=1);
namespace App\MarketOverview;

/**
 * The wider instrument list for the dashboard's "Live Market Overview"
 * table (Section 3) — deliberately separate from configured_markets(),
 * which drives the actual scanning/signal-generation pipeline (AI calls,
 * daily/weekly signal caps, risk-limit tracking). Listing instruments
 * here does NOT put them through that pipeline; it only fetches read-only
 * price/trend/volatility snapshots via PairSnapshotFetcher for display.
 * Signal/confidence columns are populated only for instruments that also
 * appear in configured_markets() — everything else honestly shows "no
 * signal" rather than implying a scan that never ran.
 *
 * Symbols follow Yahoo Finance's ticker convention (the same convention
 * configured_markets() already uses for forex), since
 * App\MarketIntelligence\PairSnapshotFetcher ultimately resolves through
 * App\MarketData\Manager\MarketDataManager's Yahoo fallback for anything
 * Deriv doesn't recognize.
 */
final class InstrumentCatalog
{
    private const INSTRUMENTS = [
        // Forex
        ['symbol' => 'EURUSD=X', 'label' => 'EUR/USD', 'category' => 'forex'],
        ['symbol' => 'GBPUSD=X', 'label' => 'GBP/USD', 'category' => 'forex'],
        ['symbol' => 'USDJPY=X', 'label' => 'USD/JPY', 'category' => 'forex'],
        ['symbol' => 'USDCHF=X', 'label' => 'USD/CHF', 'category' => 'forex'],
        ['symbol' => 'USDCAD=X', 'label' => 'USD/CAD', 'category' => 'forex'],
        ['symbol' => 'AUDUSD=X', 'label' => 'AUD/USD', 'category' => 'forex'],
        ['symbol' => 'NZDUSD=X', 'label' => 'NZD/USD', 'category' => 'forex'],
        ['symbol' => 'EURGBP=X', 'label' => 'EUR/GBP', 'category' => 'forex'],
        ['symbol' => 'EURJPY=X', 'label' => 'EUR/JPY', 'category' => 'forex'],
        ['symbol' => 'GBPJPY=X', 'label' => 'GBP/JPY', 'category' => 'forex'],
        ['symbol' => 'AUDJPY=X', 'label' => 'AUD/JPY', 'category' => 'forex'],
        ['symbol' => 'EURAUD=X', 'label' => 'EUR/AUD', 'category' => 'forex'],
        ['symbol' => 'GBPAUD=X', 'label' => 'GBP/AUD', 'category' => 'forex'],
        ['symbol' => 'EURCHF=X', 'label' => 'EUR/CHF', 'category' => 'forex'],
        ['symbol' => 'GBPCHF=X', 'label' => 'GBP/CHF', 'category' => 'forex'],

        // Metals
        ['symbol' => 'XAUUSD=X', 'label' => 'XAU/USD', 'category' => 'metals'],
        ['symbol' => 'XAGUSD=X', 'label' => 'XAG/USD', 'category' => 'metals'],

        // Indices
        ['symbol' => '^DJI',    'label' => 'US30',    'category' => 'indices'],
        ['symbol' => '^IXIC',   'label' => 'NASDAQ',  'category' => 'indices'],
        ['symbol' => '^GSPC',   'label' => 'S&P500',  'category' => 'indices'],
        ['symbol' => '^GDAXI',  'label' => 'DAX',     'category' => 'indices'],
        ['symbol' => '^FTSE',   'label' => 'FTSE100', 'category' => 'indices'],
        ['symbol' => '^N225',   'label' => 'Nikkei',  'category' => 'indices'],

        // Crypto
        ['symbol' => 'BTC-USD', 'label' => 'BTC/USD', 'category' => 'crypto'],
        ['symbol' => 'ETH-USD', 'label' => 'ETH/USD', 'category' => 'crypto'],
        ['symbol' => 'SOL-USD', 'label' => 'SOL/USD', 'category' => 'crypto'],
        ['symbol' => 'XRP-USD', 'label' => 'XRP/USD', 'category' => 'crypto'],

        // Commodities
        ['symbol' => 'CL=F', 'label' => 'Oil (WTI)',     'category' => 'commodities'],
        ['symbol' => 'NG=F', 'label' => 'Natural Gas',   'category' => 'commodities'],
    ];

    /** @return array<int, array{symbol:string, label:string, category:string}> */
    public static function all(): array
    {
        return self::INSTRUMENTS;
    }

    /** @return array<string> Category keys in display order. */
    public static function categories(): array
    {
        return ['forex', 'metals', 'indices', 'crypto', 'commodities'];
    }
}
