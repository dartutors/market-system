<?php
declare(strict_types=1);
namespace App\Risk;

/**
 * Projects take-profit levels from the risk unit already established by the
 * stop-loss distance ("1R" = the entry-to-stop distance), and can express a
 * manually chosen target back in R-multiple terms.
 */
final class TakeProfitCalculator
{
    private const DEFAULT_R_MULTIPLES = [1.0, 2.0, 3.0];

    /**
     * @param array<float> $rMultiples
     * @return array<string, float> "1R"/"2R"/"3R" => target price
     */
    public function levels(float $entry, float $stopPrice, string $direction, array $rMultiples = self::DEFAULT_R_MULTIPLES): array
    {
        $riskUnit = abs($entry - $stopPrice);
        $isBuy    = strtoupper(trim($direction)) !== 'SELL';

        $levels = [];
        foreach ($rMultiples as $r) {
            $target = $isBuy ? $entry + ($riskUnit * $r) : $entry - ($riskUnit * $r);
            $levels[self::formatR($r)] = round($target, 6);
        }

        return $levels;
    }

    /** How many multiples of the risk unit a manually chosen target represents. */
    public function achievedRMultiple(float $entry, float $stopPrice, float $targetPrice): float
    {
        $riskUnit = abs($entry - $stopPrice);
        if ($riskUnit <= 0.0) {
            return 0.0;
        }

        return round(abs($targetPrice - $entry) / $riskUnit, 2);
    }

    private static function formatR(float $r): string
    {
        $formatted = rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.');
        return $formatted . 'R';
    }
}
