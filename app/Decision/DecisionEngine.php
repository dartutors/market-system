<?php
declare(strict_types=1);
namespace App\Decision;

use App\Domain\MarketSnapshot;
use App\Models\AiLearningMemory;
use DateTimeImmutable;

/**
 * Centralized decision layer: combines technical trend/momentum, news
 * sentiment, economic-calendar risk, backtest reliability, and AI bias from
 * a MarketSnapshot into one BUY/SELL/WAIT/NO_TRADE recommendation.
 *
 * As of the Phase 2.1 foundation-enhancements sprint, this class no longer
 * builds any presentation text — it records structured facts and which
 * branch was taken; App\Explanation\ExplanationEngine renders the sentences.
 * The decision logic itself (thresholds, branching) is unchanged from the
 * prior sprint — only the output shape changed from "sentence" to "fact."
 *
 * Deliberately market-agnostic — unlike EntrySignalService's hardcoded
 * per-pair news-direction map, this reads only the qualitative trend/
 * momentum/sentiment fields already on the snapshot.
 *
 * Additive only: still a new, separate output (ai_decisions table) that
 * does not change what MarketScanService persists to signal_events, emails,
 * or returns today.
 */
final class DecisionEngine
{
    private const VALID_DECISIONS = ['BUY', 'SELL', 'WAIT', 'NO_TRADE'];
    private const MIN_ACTION_CONFIDENCE = 50.0;

    public function __construct(private ScoreEngine $scoreEngine = new ScoreEngine())
    {
    }

    /**
     * @param array $backtest     Optional BacktestService::run()-shaped stats (win_rate etc.)
     * @param array $newsSummary  Reserved for future use (AiSummaryService::summarize() shape);
     *                            the snapshot's own aiSummary/sentiment fields already carry the
     *                            data this engine needs today.
     */
    public function decide(MarketSnapshot $snapshot, array $backtest = [], array $newsSummary = []): DecisionResult
    {
        // ── Phase 2.8 AI Learning Engine: confidence calibration ───────────────
        // Reads a cheap, indexed-by-key row from App\Models\AiLearningMemory
        // (persisted by App\Learning\ConfidenceCalibrationService::remember(),
        // an explicit on-demand action — never a live aggregate query here).
        // Guarded by the feature flag so a disabled installation doesn't even
        // issue the extra query; guarded again by ScoreEngine's own weight-0.0
        // default (see App\Config\Score) so enabling the flag alone still
        // changes nothing until SCORE_WEIGHT_CALIBRATION is also set.
        $calibrationFactor = 50.0;
        if (feature_enabled('AI_LEARNING')) {
            $memory = AiLearningMemory::latest('confidence_calibration');
            if ($memory !== null && isset($memory['value']['overall_factor'])) {
                $calibrationFactor = max(0.0, min(100.0, (float) $memory['value']['overall_factor']));
            }
        }

        // ── Hard override: active high-impact economic event ──────────────────
        if ($snapshot->economicEvents !== null) {
            $eventTitle = (string) ($snapshot->economicEvents['event_title'] ?? 'a high-impact event');

            $facts = [
                'trend'              => $snapshot->trend,
                'momentum'           => $snapshot->momentum,
                'momentum_aligned'   => true,
                'news_score'         => (float) ($snapshot->sentiment['score'] ?? 0.0),
                'ai_bias'            => (string) ($snapshot->aiSummary['bias']       ?? 'mixed'),
                'ai_confidence'      => (float)  ($snapshot->aiSummary['confidence'] ?? 50.0),
                'backtest_win_rate'  => $backtest['win_rate'] ?? null,
                'calibration_factor' => $calibrationFactor,
                'calendar_blocked'   => true,
                'event_title'        => $eventTitle,
                'direction'          => null,
                'branch'             => 'calendar_blocked',
            ];

            return $this->build('WAIT', 20.0, $facts, [], 'high', $this->breakdownFor([
                'technical' => 50.0, 'momentum' => 50.0, 'news' => 50.0,
                'calendar' => 10.0, 'backtest' => 50.0, 'ai' => 50.0,
                'calibration' => $calibrationFactor,
            ]));
        }

        $direction = match ($snapshot->trend) {
            'bullish' => 'BUY',
            'bearish' => 'SELL',
            default   => null,
        };

        $momentumAligned = match (true) {
            $direction === 'BUY'  && $snapshot->momentum === 'strengthening' => true,
            $direction === 'SELL' && $snapshot->momentum === 'weakening'     => true,
            $direction === null                                              => true,
            default                                                          => false,
        };

        $conflictFlags = [];
        if (!$momentumAligned) {
            $conflictFlags[] = 'momentum_mismatch';
        }

        $newsScore  = (float) ($snapshot->sentiment['score'] ?? 0.0);
        $newsFactor = max(0.0, min(100.0, 50.0 + $newsScore / 2));

        $backtestFactor = isset($backtest['win_rate'])
            ? max(0.0, min(100.0, (float) $backtest['win_rate']))
            : 50.0;

        $aiBias       = (string) ($snapshot->aiSummary['bias']       ?? 'mixed');
        $aiConfidence = (float)  ($snapshot->aiSummary['confidence'] ?? 50.0);

        $factors = [
            'technical'   => $direction !== null ? 70.0 : 40.0,
            'momentum'    => $direction === null ? 40.0 : ($momentumAligned ? 75.0 : 30.0),
            'news'        => $newsFactor,
            'calendar'    => 70.0, // no active block, since we already returned above if there was one
            'backtest'    => $backtestFactor,
            'ai'          => $aiConfidence,
            'calibration' => $calibrationFactor,
        ];

        $score = $this->scoreEngine->score($factors);

        $facts = [
            'trend'              => $snapshot->trend,
            'momentum'           => $snapshot->momentum,
            'momentum_aligned'   => $momentumAligned,
            'news_score'         => $newsScore,
            'ai_bias'            => $aiBias,
            'ai_confidence'      => $aiConfidence,
            'backtest_win_rate'  => $backtest['win_rate'] ?? null,
            'calibration_factor' => $calibrationFactor,
            'calendar_blocked'   => false,
            'event_title'        => null,
            'direction'          => $direction,
        ];

        // Every non-calendar-blocked branch persists as 'medium' risk — this
        // matches the exact pre-Phase-4 behavior (MarketScanService's old
        // ternary only ever produced 'high' when calendarRisk was non-null;
        // every other branch got 'medium').
        if ($direction === null) {
            return $this->build('NO_TRADE', $score->confidence, $facts + ['branch' => 'no_direction'], $conflictFlags, 'medium', $score->breakdown);
        }

        if (!$momentumAligned) {
            return $this->build('WAIT', min($score->confidence, 55.0), $facts + ['branch' => 'momentum_conflict'], $conflictFlags, 'medium', $score->breakdown);
        }

        if ($score->confidence < self::MIN_ACTION_CONFIDENCE) {
            return $this->build('WAIT', $score->confidence, $facts + ['branch' => 'low_confidence'], $conflictFlags, 'medium', $score->breakdown);
        }

        return $this->build($direction, $score->confidence, $facts + ['branch' => 'aligned'], $conflictFlags, 'medium', $score->breakdown);
    }

    private function breakdownFor(array $factors): array
    {
        return $this->scoreEngine->score($factors)->breakdown;
    }

    private function build(string $decision, float $confidence, array $facts, array $conflictFlags, string $riskLevel, array $breakdown): DecisionResult
    {
        if (!\in_array($decision, self::VALID_DECISIONS, true)) {
            $decision = 'NO_TRADE';
        }

        return new DecisionResult(
            decision: $decision,
            confidence: round(max(0.0, min(100.0, $confidence)), 2),
            facts: $facts,
            conflictFlags: $conflictFlags,
            riskLevel: $riskLevel,
            scoreBreakdown: $breakdown,
            generatedAt: new DateTimeImmutable('now'),
        );
    }
}
