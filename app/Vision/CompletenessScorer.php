<?php
declare(strict_types=1);
namespace App\Vision;

/**
 * Weighted completeness percentage over App\Vision\VisionTimeframeCatalog's
 * weights — missing Monthly/Weekly hurts completeness far more than missing
 * M5/M1, since the higher timeframes establish direction. Implements the
 * spec's "never pretend missing information exists": callers are expected
 * to surface `warnings` verbatim rather than silently proceeding as if a
 * missing long-term timeframe doesn't matter.
 */
final class CompletenessScorer
{
    private const IMPORTANT_LONG_TERM = ['Monthly', 'Weekly', 'Daily'];

    /**
     * @param array<int, string> $uploadedLabels
     */
    public static function score(array $uploadedLabels): array
    {
        $uploaded = array_values(array_unique(array_filter(
            $uploadedLabels,
            static fn(string $l): bool => VisionTimeframeCatalog::has($l)
        )));

        $weights      = VisionTimeframeCatalog::weights();
        $totalWeight  = VisionTimeframeCatalog::totalWeight();
        $usedWeight   = array_sum(array_map(static fn(string $l): float => $weights[$l] ?? 0.0, $uploaded));
        $percentage   = $totalWeight > 0.0 ? round($usedWeight / $totalWeight * 100, 1) : 0.0;

        $missing          = array_values(array_diff(VisionTimeframeCatalog::labels(), $uploaded));
        $missingImportant = array_values(array_diff(self::IMPORTANT_LONG_TERM, $uploaded));

        $warnings = [];
        foreach ($missingImportant as $label) {
            $warnings[] = "{$label} was not uploaded — long-term direction may be incomplete without it.";
        }

        return [
            'percentage'        => $percentage,
            'uploaded'          => $uploaded,
            'missing'           => $missing,
            'missing_important' => $missingImportant,
            'warnings'          => $warnings,
        ];
    }
}
