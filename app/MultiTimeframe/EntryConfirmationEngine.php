<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

/**
 * Confirms entry against higher-timeframe context: a pullback on the
 * intermediate timeframes plus a resumption signal on the entry timeframes
 * (M15/M5 — the ones a trader actually times an entry against) is what
 * turns "there's a bias" into "there's a trade."
 *
 *   no_setup       — no bias, or bias exists but no pullback has occurred yet.
 *   pullback_only  — a pullback is in progress but entry timeframes haven't resumed direction.
 *   confirmed      — pullback occurred AND at least one entry timeframe has resumed the bias direction.
 */
final class EntryConfirmationEngine
{
    private const ENTRY_TIMEFRAMES = ['M15', 'M5'];

    /**
     * @param array<string, PullbackAssessment> $pullbacks
     * @param array<string, TimeframeAnalysis> $byTimeframe
     */
    public function confirm(MultiTimeframeResult $alignment, array $pullbacks, array $byTimeframe): EntryConfirmationResult
    {
        $bias = $alignment->overallBias;

        if ($bias === 'neutral') {
            return new EntryConfirmationResult(
                false,
                'no_setup',
                'No dominant multi-timeframe bias — nothing to confirm an entry against.',
                [],
                []
            );
        }

        $pullbackTimeframes = [];
        foreach ($pullbacks as $label => $assessment) {
            if ($assessment->isPullback) {
                $pullbackTimeframes[] = $label;
            }
        }

        if (empty($pullbackTimeframes)) {
            return new EntryConfirmationResult(
                false,
                'no_setup',
                "No pullback detected yet against the {$bias} bias — wait for a retracement before looking for entries.",
                [],
                []
            );
        }

        $confirmingTimeframes = [];
        foreach (self::ENTRY_TIMEFRAMES as $label) {
            $analysis = $byTimeframe[$label] ?? null;
            if ($analysis !== null && $this->confirmsDirection($analysis, $bias)) {
                $confirmingTimeframes[] = $label;
            }
        }

        if (!empty($confirmingTimeframes)) {
            $tfList = implode(' and ', $confirmingTimeframes);
            return new EntryConfirmationResult(
                true,
                'confirmed',
                "Pullback on " . implode(', ', $pullbackTimeframes) . " has resumed in the {$bias} direction on {$tfList} — entry conditions confirmed.",
                $confirmingTimeframes,
                $pullbackTimeframes
            );
        }

        return new EntryConfirmationResult(
            false,
            'pullback_only',
            "Pullback detected on " . implode(', ', $pullbackTimeframes) . ", but M15/M5 haven't yet resumed the {$bias} direction — keep watching.",
            [],
            $pullbackTimeframes
        );
    }

    private function confirmsDirection(TimeframeAnalysis $analysis, string $bias): bool
    {
        return match ($bias) {
            'bullish' => $analysis->trend === 'bullish' && $analysis->momentum === 'strengthening',
            'bearish' => $analysis->trend === 'bearish' && $analysis->momentum === 'weakening',
            default   => false,
        };
    }
}
