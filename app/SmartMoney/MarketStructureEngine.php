<?php
declare(strict_types=1);
namespace App\SmartMoney;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;
use Throwable;

/**
 * Runs every registered detector against one OHLC row series — mirrors
 * App\Patterns\PatternRecognitionEngine's shape exactly. Each detector runs
 * in its own try/catch so one bad detector degrades to "no events from that
 * one" rather than breaking the whole institutional-analysis page.
 */
final class MarketStructureEngine
{
    /** @param array<int, StructureDetectorInterface>|null $detectors */
    public function __construct(private readonly ?array $detectors = null)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $rows Chronological OHLC(V) rows.
     * @return array<int, StructureEvent> Most recent first.
     */
    public function analyze(array $rows): array
    {
        $events = [];
        foreach ($this->detectors ?? StructureDetectorRegistry::all() as $detector) {
            try {
                foreach ($detector->detect($rows) as $event) {
                    $events[] = $event;
                }
            } catch (Throwable $e) {
                error_log('[MarketStructureEngine] Detector "' . $detector->key() . '" failed: ' . $e->getMessage());
            }
        }

        usort($events, static fn(StructureEvent $a, StructureEvent $b): int => $b->endIndex <=> $a->endIndex);

        return $events;
    }

    /**
     * Groups detected events by detector key, one section per structure
     * type — the shape the Institutional Analysis view renders directly
     * (Order Blocks section, Fair Value Gaps section, etc.), including
     * empty sections for types with no current match.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array{label: string, events: array<int, StructureEvent>}>
     */
    public function analyzeGrouped(array $rows): array
    {
        $grouped = [];
        foreach (StructureDetectorRegistry::all() as $detector) {
            $grouped[$detector->key()] = ['label' => $detector->label(), 'events' => []];
        }

        foreach ($this->analyze($rows) as $event) {
            $grouped[$event->type]['events'][] = $event;
        }

        return $grouped;
    }
}
