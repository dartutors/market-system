<?php
declare(strict_types=1);
namespace App\Services;

use App\Indicators\IndicatorRegistry;

/**
 * Iterates a registry of indicator classes instead of calling hardcoded
 * private methods. Default registry reproduces the original 5-indicator
 * output exactly (see App\Indicators\IndicatorRegistry::legacyDefaults()
 * and App\Indicators\SMA/EMA/RSI/MACD/ATR, extracted verbatim from what
 * used to live in this file). Adding an indicator no longer requires
 * touching this class.
 */
final class IndicatorService
{
    private IndicatorRegistry $registry;

    public function __construct(?IndicatorRegistry $registry = null)
    {
        $this->registry = $registry ?? IndicatorRegistry::legacyDefaults();
    }

    public function enrich(array $rows): array
    {
        foreach ($this->registry->all() as $indicator) {
            $rows = $indicator->apply($rows);
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['sma_20'] ?? null) !== null && ($row['sma_50'] ?? null) !== null
        ));
    }
}
