<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Learning\ConfidenceCalibrationService;
use Throwable;

/**
 * Not a directional voter — "has this system's stated confidence
 * historically matched real outcomes" doesn't imply BUY or SELL, it's a
 * trust modifier on confidence itself. Reads App\Learning\
 * ConfidenceCalibrationService's calibration factor (50 = neutral, above =
 * historical outcomes support trusting current confidence levels, below =
 * historical evidence shows confidence gets overstated) computed from
 * signal_events — the only complete confidence-vs-outcome dataset this app
 * has. Same minimum-sample-size honesty this app applies elsewhere: near-
 * zero sample size reports as unavailable rather than a misleadingly
 * precise-looking number.
 */
final class HistoricalAccuracyVoteAdapter implements ConsensusSourceInterface
{
    private const MIN_SAMPLE_SIZE = 10;

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $calibration = (new ConfidenceCalibrationService())->calculate();
            $sampleSize = (int) $calibration['sample_size'];

            if ($sampleSize < self::MIN_SAMPLE_SIZE) {
                return new ConsensusVote(
                    'HistoricalAccuracy', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0,
                    "Insufficient track record ({$sampleSize} past signals with a recorded outcome) to weigh confidence by historical accuracy yet.",
                    true, false,
                );
            }

            $factor = (float) $calibration['overall_factor'];
            $wellCalibrated = (bool) $calibration['well_calibrated'];
            $note = $wellCalibrated
                ? 'historically well-calibrated'
                : ($factor < 50 ? 'historically overstates confidence' : 'historically understates confidence');

            return new ConsensusVote(
                source: 'HistoricalAccuracy',
                direction: ConsensusVote::DIRECTION_NEUTRAL,
                confidence: $factor,
                weight: 0.0,
                reasoning: "Calibration factor {$factor}/100 across {$sampleSize} past signals — {$note}.",
                available: true,
                isDirectional: false,
            );
        } catch (Throwable $e) {
            error_log("[HistoricalAccuracyVoteAdapter] {$label} failed: " . $e->getMessage());
            return new ConsensusVote('HistoricalAccuracy', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0, 'Calibration data unavailable.', false, false);
        }
    }
}
