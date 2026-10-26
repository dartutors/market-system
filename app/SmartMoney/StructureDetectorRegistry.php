<?php
declare(strict_types=1);
namespace App\SmartMoney;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Detectors\AccumulationDistributionDetector;
use App\SmartMoney\Detectors\BreakerBlockDetector;
use App\SmartMoney\Detectors\BreakOfStructureDetector;
use App\SmartMoney\Detectors\ChangeOfCharacterDetector;
use App\SmartMoney\Detectors\EqualHighsLowsDetector;
use App\SmartMoney\Detectors\FairValueGapDetector;
use App\SmartMoney\Detectors\LiquidityPoolDetector;
use App\SmartMoney\Detectors\LiquiditySweepDetector;
use App\SmartMoney\Detectors\MitigationBlockDetector;
use App\SmartMoney\Detectors\OrderBlockDetector;
use App\SmartMoney\Detectors\PremiumDiscountZoneDetector;

/** The full Phase 3.0/Phase 21 catalog — mirrors App\Patterns\PatternDetectorRegistry's "static list of concrete instances" convention. */
final class StructureDetectorRegistry
{
    /** @return array<int, StructureDetectorInterface> */
    public static function all(): array
    {
        return [
            new OrderBlockDetector(),
            new FairValueGapDetector(),
            new LiquidityPoolDetector(),
            new EqualHighsLowsDetector(),
            new BreakOfStructureDetector(),
            new ChangeOfCharacterDetector(),
            new PremiumDiscountZoneDetector(),
            new LiquiditySweepDetector(),
            new MitigationBlockDetector(),
            new BreakerBlockDetector(),
            new AccumulationDistributionDetector(),
        ];
    }
}
