<?php
declare(strict_types=1);
namespace App\MarketData\Providers;

/**
 * Deriv uses a different symbol dialect per asset class, not one uniform
 * transform of Yahoo's ticker style — confirmed live against the real
 * Deriv WebSocket API (ticks_history), not guessed:
 *   - Forex + metals: "EURUSD=X"/"XAUUSD=X" -> "frxEURUSD"/"frxXAUUSD"
 *     (mechanical: strip "=X", prepend "frx" — metals ride the same rule).
 *   - Crypto: "BTC-USD" -> "cryBTCUSD" (prepend "cry", not "frx" — the
 *     previous version of this class prepended "frx" to every symbol
 *     unconditionally, producing "frxBTC-USD", an invalid Deriv symbol;
 *     confirmed live that was the exact cause of every
 *     "InputValidationFailed" error logged for crypto instruments).
 *   - Real-world stock indices: no mechanical transform exists — Deriv's
 *     "OTC_" codes don't derive from the Yahoo ticker at all (e.g. Yahoo's
 *     "^GSPC" for the S&P 500 maps to Deriv's "OTC_SPC", "^IXIC" for the
 *     Nasdaq Composite maps to "OTC_NDX") — an explicit lookup table,
 *     verified live one by one, not a guessable pattern.
 * Symbols outside all of the above return null rather than a guessed
 * string — DerivProvider treats null as "not on Deriv," an instant,
 * network-free fallback to Yahoo Finance instead of a doomed round trip.
 */
final class DerivSymbolMapper
{
    private const INDEX_MAP = [
        '^DJI'   => 'OTC_DJI',    // US30 / Dow Jones Industrial Average
        '^IXIC'  => 'OTC_NDX',    // NASDAQ Composite
        '^GSPC'  => 'OTC_SPC',    // S&P 500
        '^GDAXI' => 'OTC_GDAXI',  // DAX
        '^FTSE'  => 'OTC_FTSE',   // FTSE 100
    ];

    public static function toDerivSymbol(string $yahooStyleSymbol): ?string
    {
        $symbol = trim($yahooStyleSymbol);

        if (str_ends_with($symbol, '=X')) {
            $core = strtoupper(str_replace('=X', '', $symbol));
            return 'frx' . $core;
        }

        if (preg_match('/^([A-Za-z]+)-USD$/', $symbol, $m) === 1) {
            return 'cry' . strtoupper($m[1]) . 'USD';
        }

        return self::INDEX_MAP[$symbol] ?? null;
    }

    public static function fromDerivSymbol(string $derivSymbol): ?string
    {
        $symbol = trim($derivSymbol);

        if (str_starts_with($symbol, 'frx')) {
            return strtoupper(substr($symbol, 3)) . '=X';
        }

        if (preg_match('/^cry([A-Za-z]+)USD$/', $symbol, $m) === 1) {
            return strtoupper($m[1]) . '-USD';
        }

        $flipped = array_flip(self::INDEX_MAP);
        return $flipped[$symbol] ?? null;
    }
}
