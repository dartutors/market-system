<?php
declare(strict_types=1);
namespace App\News;

/**
 * Section 8 "Categorize: Forex, Crypto, Stocks, Commodities, Central Banks".
 * This app's news providers (Marketaux/RSS/NewsAPI) don't return a category
 * field (see news_items migration), so this is a keyword heuristic derived
 * from the headline/source — an honest best-effort classification, not a
 * sourced fact. Checked most-specific-first: a headline mentioning both
 * "Fed" and "Dollar" is a central-bank story before it's a generic forex one.
 */
final class NewsCategorizer
{
    private const CENTRAL_BANK_KEYWORDS = [
        'fed', 'fomc', 'federal reserve', 'ecb', 'european central bank', 'boe', 'bank of england',
        'boj', 'bank of japan', 'rba', 'reserve bank', 'rbnz', 'snb', 'swiss national bank',
        'pboc', 'rate decision', 'rate hike', 'rate cut', 'interest rate', 'central bank', 'monetary policy',
    ];

    private const CRYPTO_KEYWORDS = [
        'bitcoin', 'btc', 'ethereum', 'eth', 'crypto', 'blockchain', 'altcoin', 'xrp', 'ripple',
        'solana', 'sol ', 'stablecoin', 'defi', 'nft',
    ];

    private const COMMODITY_KEYWORDS = [
        'gold', 'silver', 'xau', 'xag', 'oil', 'crude', 'wti', 'brent', 'commodity', 'commodities', 'natural gas',
    ];

    private const STOCK_KEYWORDS = [
        'stock', 'shares', 'equities', 'equity', 'nasdaq', 'dow jones', 's&p', 'earnings', 'ipo', 'wall street',
    ];

    public static function categorize(string $title, string $source): string
    {
        $haystack = strtolower($title . ' ' . $source);

        if (self::matchesAny($haystack, self::CENTRAL_BANK_KEYWORDS)) {
            return 'Central Banks';
        }
        if (self::matchesAny($haystack, self::CRYPTO_KEYWORDS)) {
            return 'Crypto';
        }
        if (self::matchesAny($haystack, self::COMMODITY_KEYWORDS)) {
            return 'Commodities';
        }
        if (self::matchesAny($haystack, self::STOCK_KEYWORDS)) {
            return 'Stocks';
        }

        // This app is forex-first — everything else (currency pairs, general
        // macro headlines) defaults here rather than an unhelpful "General".
        return 'Forex';
    }

    private static function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($haystack, $kw)) {
                return true;
            }
        }
        return false;
    }
}
