<?php
declare(strict_types=1);
namespace App\Learning;

use App\Models\AiLearningMemory;
use App\Models\SignalEvent;

/**
 * Compares stated confidence bands against what actually happened
 * (SignalEvent::stats()['by_confidence'] — the only complete confidence-vs-
 * outcome dataset in this system; App\Models\AiDecision has no outcome
 * column and App\Models\TradeJournal has no confidence column, so neither
 * supports this correlation directly). Produces a single 0-100
 * "calibration factor": 50 is neutral (no evidence either way), above 50
 * means historical outcomes support trusting current confidence levels (or
 * the system undersells itself), below 50 means historical evidence shows
 * the system overstates confidence and recommendations should be tempered.
 *
 * This factor is what App\Decision\DecisionEngine reads (via
 * App\Models\AiLearningMemory::latest(), never recomputing this aggregate
 * live) to feed its optional 7th ScoreEngine factor — see
 * App\Config\Score::DEFAULT_WEIGHTS' 'calibration' entry, which defaults to
 * weight 0.0 (zero effect) until explicitly configured.
 */
final class ConfidenceCalibrationService
{
    private const BAND_ASSUMED_CONFIDENCE = [
        '80%+'      => 85.0,
        '70-79%'    => 75.0,
        '60-69%'    => 65.0,
        'Below 60%' => 50.0,
    ];

    private const MEMORY_KEY = 'confidence_calibration';

    /**
     * @return array{overall_factor:float, avg_calibration_error:float, sample_size:int,
     *   by_band:array<int,array{band:string,sample_size:int,actual_win_rate:float,assumed_confidence:float,calibration_error:float}>,
     *   well_calibrated:bool}
     */
    public function calculate(): array
    {
        return self::calculateFromBands(SignalEvent::stats()['by_confidence']);
    }

    /**
     * Pure calculation, split out from calculate() so it's testable against
     * a synthetic $bands fixture without touching the real signal_events
     * table (which has no safe delete path to clean up test rows with).
     *
     * @param array<int,array{band:string,total:int,wins:int}> $bands
     * @return array{overall_factor:float, avg_calibration_error:float, sample_size:int,
     *   by_band:array<int,array{band:string,sample_size:int,actual_win_rate:float,assumed_confidence:float,calibration_error:float}>,
     *   well_calibrated:bool}
     */
    public static function calculateFromBands(array $bands): array
    {
        $byBand           = [];
        $weightedErrorSum  = 0.0;
        $totalSample       = 0;

        foreach ($bands as $band) {
            $bandName = (string) $band['band'];
            $total    = (int) $band['total'];
            $wins     = (int) $band['wins'];

            $actualWinRate = $total > 0 ? round($wins / $total * 100, 1) : 0.0;
            $assumed       = self::BAND_ASSUMED_CONFIDENCE[$bandName] ?? 50.0;
            $error         = $actualWinRate - $assumed;

            $byBand[] = [
                'band'               => $bandName,
                'sample_size'        => $total,
                'actual_win_rate'    => $actualWinRate,
                'assumed_confidence' => $assumed,
                'calibration_error'  => round($error, 1),
            ];

            $weightedErrorSum += $error * $total;
            $totalSample      += $total;
        }

        $avgError      = $totalSample > 0 ? $weightedErrorSum / $totalSample : 0.0;
        $overallFactor = round(max(0.0, min(100.0, 50.0 + $avgError)), 2);

        return [
            'overall_factor'         => $overallFactor,
            'avg_calibration_error'  => round($avgError, 2),
            'sample_size'            => $totalSample,
            'by_band'                => $byBand,
            'well_calibrated'        => abs($avgError) <= 5.0,
        ];
    }

    /** Computes and persists a new AI-memory snapshot; this is the explicit "teach it" action. */
    public function remember(): array
    {
        $data = $this->calculate();
        AiLearningMemory::remember(self::MEMORY_KEY, $data, $data['sample_size']);
        return $data;
    }

    public static function memoryKey(): string
    {
        return self::MEMORY_KEY;
    }
}
