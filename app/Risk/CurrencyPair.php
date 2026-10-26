<?php
declare(strict_types=1);
namespace App\Risk;

/**
 * Parses a "BASE/QUOTE" market label (the only label shape this system's
 * configured_markets() produces — e.g. "EUR/USD", "USD/JPY") into its two
 * currencies, and expresses a direction as a signed exposure vector. Used by
 * ExposureTracker and CorrelationAnalyzer so both share one parsing rule
 * instead of re-deriving it.
 */
final class CurrencyPair
{
    public function __construct(
        public readonly string $base,
        public readonly string $quote,
    ) {
    }

    public static function parse(string $label): self
    {
        $parts = array_map('trim', explode('/', strtoupper(trim($label))));

        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            return new self($parts[0], $parts[1]);
        }

        // Defensive fallback for a malformed label — every market configured
        // in this system today is quoted against USD.
        return new self(strtoupper(trim($label)) ?: 'UNKNOWN', 'USD');
    }

    /**
     * @return array<string,int> Currency => +1 (net long) / -1 (net short) for one unit of exposure.
     *         BUY EUR/USD: +1 EUR, -1 USD. SELL EUR/USD: -1 EUR, +1 USD.
     */
    public static function exposureVector(string $label, string $direction): array
    {
        $pair = self::parse($label);
        $sign = strtoupper(trim($direction)) === 'SELL' ? -1 : 1;

        return [
            $pair->base  => $sign,
            $pair->quote => -$sign,
        ];
    }
}
