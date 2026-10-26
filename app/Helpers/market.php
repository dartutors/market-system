<?php
declare(strict_types=1);

/**
 * Returns all configured markets as an array of [symbol, label] pairs.
 * Reads from MARKETS env var (comma-separated symbols).
 */
function configured_markets(): array
{
    $raw     = trim((string) env('MARKETS', 'EURUSD=X,GBPUSD=X,USDJPY=X'));
    $symbols = array_filter(array_map('trim', explode(',', $raw)));

    $markets = [];
    foreach ($symbols as $symbol) {
        $markets[] = [
            'symbol' => $symbol,
            'label'  => market_label_from_symbol($symbol),
        ];
    }

    return array_values($markets);
}

/**
 * Resolves a market symbol to its human-readable label.
 * Reads from MARKET_{KEY}_LABEL env var first, then falls back to built-ins.
 */
function market_label_from_symbol(string $symbol): string
{
    // Build env key: EURUSD=X → MARKET_EURUSD_LABEL
    $key   = 'MARKET_' . strtoupper(str_replace('=X', '', $symbol)) . '_LABEL';
    $label = trim((string) env($key, ''));

    if ($label !== '') {
        return $label;
    }

    return match ($symbol) {
        'EURUSD=X' => 'EUR/USD',
        'GBPUSD=X' => 'GBP/USD',
        'USDJPY=X' => 'USD/JPY',
        default    => $symbol,
    };
}

/**
 * Legacy helper — returns the single configured market label.
 * Kept for backward compatibility.
 */
function allowed_market(): string
{
    return (string) env('MARKET_LABEL', 'EUR/USD');
}
