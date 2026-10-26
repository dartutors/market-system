<?php
declare(strict_types=1);
namespace App\Risk;

/**
 * Picks the nearest usable stop/target price from a set of detected support
 * and resistance levels — used to seed a risk assessment from an AI Vision
 * chart read (App\Controllers\VisionController::buildRiskGuidance()) when
 * the user hasn't manually overridden the stop/target.
 */
final class LevelPicker
{
    /** @return array<float> Positive numeric values only, in original order. */
    public static function numericLevels(mixed $levels): array
    {
        if (!\is_array($levels)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($v): ?float => is_numeric($v) ? (float) $v : null,
            $levels
        ), static fn(?float $v): bool => $v !== null && $v > 0.0));
    }

    /**
     * @param array<float> $supportLevels
     * @param array<float> $resistanceLevels
     * @return array{0: ?float, 1: ?float} [stop, target]
     */
    public static function nearestStopAndTarget(string $direction, float $entry, array $supportLevels, array $resistanceLevels): array
    {
        if (strtoupper(trim($direction)) === 'SELL') {
            $stopCandidates   = array_filter($resistanceLevels, static fn(float $l): bool => $l > $entry);
            $targetCandidates = array_filter($supportLevels,    static fn(float $l): bool => $l < $entry);
            $stop   = empty($stopCandidates)   ? null : min($stopCandidates);
            $target = empty($targetCandidates) ? null : max($targetCandidates);
            return [$stop, $target];
        }

        $stopCandidates   = array_filter($supportLevels,    static fn(float $l): bool => $l < $entry);
        $targetCandidates = array_filter($resistanceLevels, static fn(float $l): bool => $l > $entry);
        $stop   = empty($stopCandidates)   ? null : max($stopCandidates);
        $target = empty($targetCandidates) ? null : min($targetCandidates);
        return [$stop, $target];
    }
}
