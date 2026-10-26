<?php
declare(strict_types=1);
namespace App\Reports;

use App\MarketIntelligence\MarketIntelligenceEngine;
use App\Models\CurrencyStrengthSnapshot;
use Throwable;

/**
 * "Currency Strength" report. History is real but young: this app never
 * persisted currency-strength readings before this report shipped (see
 * migration 035 / CurrencyStrengthSnapshot) — it collects one snapshot set
 * per ~15 minutes of real dashboard traffic going forward, so early views
 * of this report will show a short trend that grows over time rather than
 * a fabricated deep history.
 */
final class CurrencyStrengthReport
{
    public static function build(): array
    {
        $current = [];
        try {
            $snap = (new MarketIntelligenceEngine())->build();
            $current = $snap->currencyStrength;
        } catch (Throwable) {
            // Live snapshot unavailable right now — history below still renders independently.
        }

        $history = [];
        foreach (array_keys($current) ?: ['USD', 'EUR', 'GBP', 'JPY'] as $currency) {
            $history[$currency] = CurrencyStrengthSnapshot::history($currency, 96);
        }

        return [
            'current'          => $current,
            'history'          => $history,
            'collecting_since' => CurrencyStrengthSnapshot::earliestCapturedAt(),
        ];
    }

    public static function toTable(array $report): array
    {
        $rows = [];
        foreach ($report['current'] as $currency => $score) {
            $rows[] = [$currency, ($score >= 0 ? '+' : '') . number_format($score, 2) . '%'];
        }
        return ['headers' => ['Currency', 'Strength'], 'rows' => $rows];
    }
}
