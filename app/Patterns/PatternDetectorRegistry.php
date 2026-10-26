<?php
declare(strict_types=1);
namespace App\Patterns;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Detectors\BreakoutDetector;
use App\Patterns\Detectors\ChannelDetector;
use App\Patterns\Detectors\CupAndHandleDetector;
use App\Patterns\Detectors\DoubleBottomDetector;
use App\Patterns\Detectors\DoubleTopDetector;
use App\Patterns\Detectors\FlagDetector;
use App\Patterns\Detectors\HeadAndShouldersDetector;
use App\Patterns\Detectors\InverseHeadAndShouldersDetector;
use App\Patterns\Detectors\PennantDetector;
use App\Patterns\Detectors\PullbackPatternDetector;
use App\Patterns\Detectors\RangeDetector;
use App\Patterns\Detectors\RectangleDetector;
use App\Patterns\Detectors\TriangleDetector;
use App\Patterns\Detectors\WedgeDetector;

/**
 * The full Phase 2.9 catalog — mirrors App\Strategies\StrategyRegistry's
 * "static list of concrete instances" convention. Future additions (ICT,
 * Smart Money Concepts, Elliott Wave) are explicitly out of scope per the
 * brief and are not stubbed here.
 */
final class PatternDetectorRegistry
{
    /** @return array<int, PatternDetectorInterface> */
    public static function all(): array
    {
        return [
            new DoubleTopDetector(),
            new DoubleBottomDetector(),
            new HeadAndShouldersDetector(),
            new InverseHeadAndShouldersDetector(),
            new TriangleDetector(),
            new WedgeDetector(),
            new ChannelDetector(),
            new RectangleDetector(),
            new BreakoutDetector(),
            new PullbackPatternDetector(),
            new RangeDetector(),
            new CupAndHandleDetector(),
            new FlagDetector(),
            new PennantDetector(),
        ];
    }
}
