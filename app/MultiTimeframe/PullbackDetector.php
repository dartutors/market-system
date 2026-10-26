<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

/**
 * Distinguishes a healthy retracement from a genuine trend conflict.
 *
 * TrendAlignmentEngine already flags any timeframe whose trend opposes the
 * overall bias as "conflicting" — but a lower timeframe dipping toward its
 * SMA20 (or cooling off in RSI) while price is still on the correct side of
 * SMA50 is normal pullback behavior in a healthy trend, not a reversal
 * warning. This only ever assesses timeframes against a non-neutral overall
 * bias — with no dominant bias there is nothing to pull back toward.
 */
final class PullbackDetector
{
    /** Retracement tolerance in ATR multiples — how far from SMA20 still counts as "near the mean". */
    private const NEAR_MEAN_ATR_MULTIPLE = 1.5;

    private const BULLISH_RSI_COOLING_MIN = 35.0;
    private const BULLISH_RSI_COOLING_MAX = 55.0;
    private const BEARISH_RSI_COOLING_MIN = 45.0;
    private const BEARISH_RSI_COOLING_MAX = 65.0;

    /**
     * @param array<string, TimeframeAnalysis> $byTimeframe
     * @return array<string, PullbackAssessment> Keyed by label.
     */
    public function detect(MultiTimeframeResult $alignment, array $byTimeframe): array
    {
        $bias = $alignment->overallBias;

        $assessments = [];
        foreach ($byTimeframe as $label => $analysis) {
            $assessments[$label] = $bias === 'neutral'
                ? new PullbackAssessment($label, false, 'No dominant multi-timeframe bias to pull back toward.')
                : $this->assessOne($label, $analysis, $bias);
        }

        return $assessments;
    }

    private function assessOne(string $label, TimeframeAnalysis $analysis, string $bias): PullbackAssessment
    {
        // A timeframe already aligned with the overall bias isn't retracing —
        // it's confirming. Pullback assessment only applies to timeframes
        // that are neutral or opposing.
        if ($analysis->trend === $bias) {
            return new PullbackAssessment($label, false, "{$label} is already aligned with the {$bias} bias — not a pullback.");
        }

        $structureIntact = $bias === 'bullish'
            ? $analysis->close > $analysis->sma50
            : $analysis->close < $analysis->sma50;

        if (!$structureIntact) {
            return new PullbackAssessment(
                $label,
                false,
                "{$label} has broken past its SMA50 against the {$bias} bias — looks like structure damage, not a pullback."
            );
        }

        $atrBand   = $analysis->atr14 * self::NEAR_MEAN_ATR_MULTIPLE;
        $nearMean  = abs($analysis->close - $analysis->sma20) <= $atrBand;
        $rsiCooling = $bias === 'bullish'
            ? ($analysis->rsi14 >= self::BULLISH_RSI_COOLING_MIN && $analysis->rsi14 <= self::BULLISH_RSI_COOLING_MAX)
            : ($analysis->rsi14 >= self::BEARISH_RSI_COOLING_MIN && $analysis->rsi14 <= self::BEARISH_RSI_COOLING_MAX);

        if ($nearMean || $rsiCooling) {
            return new PullbackAssessment(
                $label,
                true,
                "{$label} is retracing toward its mean while the {$bias} structure (SMA50) holds — a healthy pullback, not a reversal."
            );
        }

        return new PullbackAssessment(
            $label,
            false,
            "{$label} opposes the {$bias} bias but isn't in a recognizable pullback zone yet."
        );
    }
}
