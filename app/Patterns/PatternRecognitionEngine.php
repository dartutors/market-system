<?php
declare(strict_types=1);
namespace App\Patterns;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use Throwable;

/**
 * Runs every registered detector against one OHLC row series and returns
 * every match found, most-confident first. This is the single entry point
 * both the Scanner (App\Services\MarketScanService) and AI Vision
 * (App\Controllers\VisionController) are meant to consume — "Reusable
 * pattern detection for both scanner and AI Vision" per the Phase 2.9 brief.
 *
 * A single misbehaving detector can never take the whole engine down: each
 * runs in its own try/catch so one bad detector degrades to "no match from
 * that detector" rather than breaking pattern detection (and therefore the
 * Scanner or Vision request) entirely.
 */
final class PatternRecognitionEngine
{
    /** @param array<int, PatternDetectorInterface>|null $detectors */
    public function __construct(private readonly ?array $detectors = null)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $rows Chronological OHLC(V) rows.
     * @return array<int, PatternMatch> Sorted by confidence, descending.
     */
    public function detect(array $rows): array
    {
        $matches = [];
        foreach ($this->detectors ?? PatternDetectorRegistry::all() as $detector) {
            try {
                foreach ($detector->detect($rows) as $match) {
                    $matches[] = $match;
                }
            } catch (Throwable $e) {
                error_log('[PatternRecognitionEngine] Detector "' . $detector->key() . '" failed: ' . $e->getMessage());
            }
        }

        usort($matches, static fn(PatternMatch $a, PatternMatch $b): int => $b->confidence <=> $a->confidence);

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, PatternMatch> Only matches at or above $minConfidence.
     */
    public function detectAboveConfidence(array $rows, float $minConfidence = 60.0): array
    {
        return array_values(array_filter(
            $this->detect($rows),
            static fn(PatternMatch $m): bool => $m->confidence >= $minConfidence
        ));
    }
}
