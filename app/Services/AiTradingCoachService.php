<?php
declare(strict_types=1);
namespace App\Services;

use App\Learning\ConfidenceCalibrationService;
use App\Models\Achievement;
use App\Models\TradeJournal;
use App\TradeJournal\EmotionCatalog;
use App\TradeJournal\MistakeTagCatalog;
use Throwable;

/**
 * The pedagogical layer on top of the Decision Intelligence Engine and
 * Trade Journal — explains, critiques, and teaches, but never decides
 * (that's still DecisionIntelligenceEngine's job exclusively) and never
 * fabricates a number it can't ground in real data. Every method here is
 * rule-based against already-computed/persisted data — no new AI calls,
 * per this phase's own performance requirement ("reuses existing Decision
 * Intelligence outputs", "does not trigger unnecessary AI calls"). The one
 * exception is the pre-existing App\TradeJournal\JournalCoachingService::
 * perEntryFeedback() freeform-paragraph AI call (built last phase, left
 * untouched) — reviewClosedTrade() below is a distinct, structured,
 * non-AI scored breakdown alongside it, not a replacement.
 *
 * Honesty rule applied throughout: every score, grade, and lesson either
 * comes from a real computable signal (mistake tags actually logged,
 * emotion actually logged, real historical win rates with disclosed
 * sample sizes) or is explicitly reported as "insufficient data" — never a
 * plausible-looking placeholder.
 */
final class AiTradingCoachService
{
    private const MIN_SAMPLE_FOR_LESSON = 8;
    private const MIN_SAMPLE_FOR_RELIABILITY = 5;

    // ── Objective 2: post-analysis coaching ─────────────────────────────────

    /**
     * Wraps a persisted App\Models\ConsensusDecision row in mentor-style
     * framing. Deliberately does not call an AI provider — the Decision
     * Intelligence Engine already computed real conflicts, invalidation
     * conditions, and supporting evidence; this assembles and narrates
     * that, it doesn't recompute or re-ask for it (same principle
     * App\TradeCoach's own prompt template already enforces for its
     * AI-narrated layer).
     *
     * @return array{decision:string, why:array<int,string>, supporting_evidence:array<int,string>, conflicting_evidence:array<int,string>, recommendation:string, estimated_review_time:?string}
     */
    public function explainDecision(array $decisionRow): array
    {
        $decision = (string) $decisionRow['overall_decision'];
        $sources = json_decode((string) $decisionRow['sources_json'], true) ?: [];
        $conflicts = json_decode((string) ($decisionRow['conflicts_json'] ?? '[]'), true) ?: [];
        $invalidation = json_decode((string) ($decisionRow['invalidation_conditions_json'] ?? '[]'), true) ?: [];

        // Objective 9 wants supporting and conflicting evidence reported
        // separately, not blended into one list — a BUY made despite some
        // friction (thin liquidity, off-session) should show both, clearly
        // labeled, not read as if nothing was against it.
        $supportingEvidence = [];
        if ($decision === 'BUY' || $decision === 'SELL') {
            foreach ($sources as $s) {
                if (!empty($s['is_directional']) && !empty($s['available']) && $s['direction'] === $decision) {
                    $supportingEvidence[] = "{$s['source']}: {$s['reasoning']}";
                }
            }
        }
        $conflictingEvidence = array_map(static fn(array $c): string => $c['description'], $conflicts);

        $why = $decision === 'WAIT' ? $conflictingEvidence : $supportingEvidence;
        if ($why === []) {
            $why[] = 'Evidence across sources is currently balanced or too sparse for a clear directional case.';
        }

        $recommendation = match ($decision) {
            'BUY', 'SELL' => "Consider a {$decision} following the trade plan, sized to your own risk tolerance. "
                . ($invalidation !== [] ? 'Re-check this call if: ' . $invalidation[0] : 'Reassess if market conditions shift materially.'),
            default => $invalidation !== []
                // DecisionIntelligenceEngine's own invalidation strings are
                // already prefixed ("Resolution of: ...", "More data from
                // ...") — strip that prefix here rather than double it up
                // under this method's own "Wait for: " framing.
                ? 'Wait for: ' . lcfirst(preg_replace('/^(Resolution of|More data from):\s*/i', '', $invalidation[0]))
                    . (\count($invalidation) > 1 ? ' (and ' . (\count($invalidation) - 1) . ' more condition(s) — see full breakdown).' : '.')
                : 'Wait for stronger, more consistent evidence before considering an entry.',
        };

        return [
            'decision'              => $decision,
            'why'                   => array_slice($why, 0, 5),
            'supporting_evidence'   => $supportingEvidence,
            'conflicting_evidence'  => $conflictingEvidence,
            'recommendation'        => $recommendation,
            'estimated_review_time' => $decisionRow['next_review_at'] ?? null,
        ];
    }

    // ── Objectives 3/4: post-trade review + report card ─────────────────────

    /**
     * Scores a closed trade across 7 dimensions, 0-100 each, from real
     * signals only (logged mistake tags, logged emotion, session, and a
     * best-effort — explicitly labeled as such — match to the nearest
     * Decision Intelligence record before entry, since trade_journal has
     * no authoritative link to consensus_decisions). A dimension with no
     * real signal to grade on reports score=null and an "insufficient
     * data" reason rather than a guessed number, and is excluded from the
     * overall-grade average rather than dragging it toward a fake midpoint.
     *
     * @return array{scores: array<string, array{score:?int, reason:string}>, overall_score:?float, overall_grade:string}
     */
    public function reviewClosedTrade(array $journalRow): array
    {
        $mistakeTags = json_decode((string) ($journalRow['mistake_tags_json'] ?? '[]'), true) ?: [];
        $mistakeTags = \is_array($mistakeTags) ? $mistakeTags : [];
        $emotion = $journalRow['emotion'] ?? null;
        $outcome = (string) ($journalRow['outcome'] ?? '');
        $sessionName = (string) ($journalRow['session_name'] ?? '');
        $marketLabel = (string) ($journalRow['market_label'] ?? '');

        $nearestDecision = $marketLabel !== '' && !empty($journalRow['created_at'])
            ? $this->nearestDecisionBefore($marketLabel, (string) $journalRow['created_at'])
            : null;

        $scores = [
            'Trend Following'    => $this->scoreTrendFollowing($journalRow, $nearestDecision),
            'Risk Management'    => $this->scoreRiskManagement($mistakeTags),
            'Entry Timing'       => $this->scoreEntryTiming($mistakeTags),
            'Exit Timing'        => $this->scoreExitTiming($mistakeTags, $outcome),
            'Psychology'         => $this->scorePsychology($mistakeTags, $emotion),
            'News Awareness'     => $this->scoreNewsAwareness($mistakeTags, $nearestDecision),
            'Session Selection'  => $this->scoreSessionSelection($sessionName),
        ];

        $available = array_filter($scores, static fn(array $s): bool => $s['score'] !== null);
        $overallScore = $available !== []
            ? round(array_sum(array_column($available, 'score')) / \count($available), 1)
            : null;

        return [
            'scores'        => $scores,
            'overall_score' => $overallScore,
            'overall_grade' => $overallScore !== null ? $this->letterGrade($overallScore) : 'N/A',
        ];
    }

    private function scoreTrendFollowing(array $journalRow, ?array $nearestDecision): array
    {
        if ($nearestDecision === null) {
            return ['score' => null, 'reason' => 'No nearby Decision Intelligence record found for this pair/time — cannot verify timeframe alignment at entry.'];
        }
        $direction = strtoupper((string) ($journalRow['direction'] ?? ''));
        if ($direction === '') {
            return ['score' => null, 'reason' => 'No trade direction logged.'];
        }

        $sources = json_decode((string) $nearestDecision['sources_json'], true) ?: [];
        $trend = null;
        foreach ($sources as $s) {
            if ($s['source'] === 'TrendAlignment' && !empty($s['available'])) {
                $trend = $s;
                break;
            }
        }
        if ($trend === null) {
            return ['score' => null, 'reason' => 'No multi-timeframe trend data was available at the nearest recorded evidence snapshot.'];
        }

        if ($trend['direction'] === $direction) {
            return ['score' => 95, 'reason' => "Traded with the higher-timeframe bias (approximate match, nearest evidence snapshot {$nearestDecision['generated_at']})."];
        }
        if ($trend['direction'] === 'NEUTRAL') {
            return ['score' => 65, 'reason' => 'Higher-timeframe bias was neutral at the nearest evidence snapshot — neither confirming nor contradicting.'];
        }
        return ['score' => 30, 'reason' => "Traded against the higher-timeframe bias (approximate match, nearest evidence snapshot {$nearestDecision['generated_at']})."];
    }

    private function scoreRiskManagement(array $tags): array
    {
        $score = 100;
        $hit = [];
        foreach (['no_stop_loss' => 40, 'oversized_position' => 25, 'moved_stop_loss' => 20] as $tag => $penalty) {
            if (\in_array($tag, $tags, true)) {
                $score -= $penalty;
                $hit[] = MistakeTagCatalog::label($tag);
            }
        }
        $score = max(0, $score);
        return ['score' => $score, 'reason' => $hit === [] ? 'No risk-management mistakes logged for this trade.' : 'Logged: ' . implode(', ', $hit) . '.'];
    }

    private function scoreEntryTiming(array $tags): array
    {
        $score = 100;
        $hit = [];
        foreach (['entered_early' => 30, 'ignored_signal' => 25] as $tag => $penalty) {
            if (\in_array($tag, $tags, true)) {
                $score -= $penalty;
                $hit[] = MistakeTagCatalog::label($tag);
            }
        }
        $score = max(0, $score);
        return ['score' => $score, 'reason' => $hit === [] ? 'No entry-timing mistakes logged.' : 'Logged: ' . implode(', ', $hit) . '.'];
    }

    private function scoreExitTiming(array $tags, string $outcome): array
    {
        $score = 100;
        $hit = [];
        foreach (['no_exit_plan' => 35, 'overtraded' => 15] as $tag => $penalty) {
            if (\in_array($tag, $tags, true)) {
                $score -= $penalty;
                $hit[] = MistakeTagCatalog::label($tag);
            }
        }
        $score = max(0, $score);
        $note = $hit === [] ? 'No exit-discipline mistakes logged' : 'Logged: ' . implode(', ', $hit);
        // Weakest-signal dimension — trade_journal has no take-profit/exit-plan
        // field to check against, only mistake tags and the final outcome.
        return ['score' => $score, 'reason' => $note . ' (based on logged mistake tags and outcome only — this app does not capture a separate exit-plan field to verify against).'];
    }

    private function scorePsychology(array $tags, ?string $emotion): array
    {
        // Two independent components — a mistake-tag penalty score
        // (starts perfect, docked for each psychology-related mistake
        // logged) and an emotion-based score (a fixed read of the logged
        // emotion label) — averaged when both exist, used alone when only
        // one does, "insufficient data" when neither does.
        $tagScore = 100;
        $hit = [];
        foreach (['fomo_entry' => 30, 'revenge_trade' => 40] as $tag => $penalty) {
            if (\in_array($tag, $tags, true)) {
                $tagScore -= $penalty;
                $hit[] = MistakeTagCatalog::label($tag);
            }
        }
        $hasTagSignal = $hit !== [];

        $emotionScore = $emotion !== null ? match ($emotion) {
            'confident', 'disciplined', 'calm' => 90,
            'hesitant' => 70,
            'anxious' => 55,
            'fomo', 'greedy', 'frustrated' => 35,
            default => 65,
        } : null;

        if (!$hasTagSignal && $emotionScore === null) {
            return ['score' => null, 'reason' => 'No emotion logged and no psychology-related mistakes tagged.'];
        }

        $score = match (true) {
            $hasTagSignal && $emotionScore !== null => (int) round(($tagScore + $emotionScore) / 2),
            $emotionScore !== null => $emotionScore,
            default => $tagScore,
        };
        $score = max(0, min(100, $score));

        $parts = [];
        if ($emotion !== null) $parts[] = 'Logged emotion: ' . EmotionCatalog::label($emotion);
        if ($hit !== []) $parts[] = 'Logged: ' . implode(', ', $hit);
        elseif ($emotion !== null) $parts[] = 'no psychology-related mistakes tagged';
        return ['score' => $score, 'reason' => implode(' · ', $parts)];
    }

    private function scoreNewsAwareness(array $tags, ?array $nearestDecision): array
    {
        if (\in_array('ignored_calendar', $tags, true)) {
            return ['score' => 20, 'reason' => 'Logged: ' . MistakeTagCatalog::label('ignored_calendar') . '.'];
        }

        if ($nearestDecision !== null) {
            $conflicts = json_decode((string) ($nearestDecision['conflicts_json'] ?? '[]'), true) ?: [];
            foreach ($conflicts as $c) {
                if ($c['type'] === 'calendar_risk_window') {
                    return ['score' => 60, 'reason' => 'An active high-impact calendar window was flagged near this trade\'s entry, and no calendar mistake was self-logged — worth double-checking.'];
                }
            }
        }

        return ['score' => 100, 'reason' => 'No calendar-related mistake logged, and no active high-impact event flagged near entry.'];
    }

    private function scoreSessionSelection(string $sessionName): array
    {
        if ($sessionName === '') {
            return ['score' => null, 'reason' => 'No session logged for this trade.'];
        }

        $base = match ($sessionName) {
            'london', 'new_york' => 85,
            'tokyo', 'sydney' => 65,
            default => 45,
        };

        try {
            $stats = TradeJournal::stats();
            foreach ($stats['by_session'] as $row) {
                if ((string) $row['session_name'] === $sessionName && (int) $row['total'] >= 5) {
                    $sessionWinRate = (int) $row['total'] > 0 ? ((int) $row['wins'] / (int) $row['total']) * 100 : 0;
                    $overallWinRate = (float) $stats['win_rate'];
                    if ($sessionWinRate > $overallWinRate + 10) $base = min(100, $base + 10);
                    elseif ($sessionWinRate < $overallWinRate - 10) $base = max(0, $base - 10);
                    break;
                }
            }
        } catch (Throwable) {
            // fall back to the base session-tier score
        }

        return ['score' => $base, 'reason' => ucfirst(str_replace('_', ' ', $sessionName)) . ' session' . ($sessionName === 'off' ? ' (outside major hours)' : '') . '.'];
    }

    private function letterGrade(float $score): string
    {
        return match (true) {
            $score >= 97 => 'A+', $score >= 93 => 'A', $score >= 90 => 'A-',
            $score >= 87 => 'B+', $score >= 83 => 'B', $score >= 80 => 'B-',
            $score >= 77 => 'C+', $score >= 73 => 'C', $score >= 70 => 'C-',
            $score >= 60 => 'D', default => 'F',
        };
    }

    /** Best-effort, explicitly approximate — no real FK exists between trade_journal and consensus_decisions (see class docblock). */
    private function nearestDecisionBefore(string $marketLabel, string $beforeTimestamp): ?array
    {
        try {
            $pdo = \App\Core\Database::connection();
            $stmt = $pdo->prepare(
                'SELECT * FROM consensus_decisions
                 WHERE market_label = :label AND generated_at <= :before
                 ORDER BY generated_at DESC LIMIT 1'
            );
            $stmt->execute(['label' => $marketLabel, 'before' => $beforeTimestamp]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            // Don't match anything more than 48h stale — too far from
            // entry to honestly represent "the evidence at the time."
            $ageHours = (strtotime($beforeTimestamp) - strtotime((string) $row['generated_at'])) / 3600;
            return $ageHours <= 48 ? $row : null;
        } catch (Throwable $e) {
            error_log('[AiTradingCoachService] nearestDecisionBefore failed: ' . $e->getMessage());
            return null;
        }
    }

    // ── Objective 6: reliability, separate from confidence ──────────────────

    /**
     * @return array{grade:string, facts:array<int,string>, sample_size:int, insufficient_data:bool}
     */
    public function reliabilityScore(string $marketLabel, array $currentSources = []): array
    {
        try {
            $calibration = (new ConfidenceCalibrationService())->calculate();
        } catch (Throwable $e) {
            error_log('[AiTradingCoachService] reliabilityScore calibration failed: ' . $e->getMessage());
            return ['grade' => 'N/A', 'facts' => ['Calibration data unavailable.'], 'sample_size' => 0, 'insufficient_data' => true];
        }

        $sampleSize = (int) $calibration['sample_size'];
        if ($sampleSize < self::MIN_SAMPLE_FOR_RELIABILITY) {
            return [
                'grade'             => 'N/A',
                'facts'             => ["Only {$sampleSize} historical decisions with a recorded outcome — need at least " . self::MIN_SAMPLE_FOR_RELIABILITY . ' for a reliability grade.'],
                'sample_size'       => $sampleSize,
                'insufficient_data' => true,
            ];
        }

        $factor = (float) $calibration['overall_factor'];
        $facts = ["{$sampleSize} historical decisions with a recorded outcome"];

        $alignmentPoints = 0;
        foreach ($currentSources as $s) {
            if ($s['source'] === 'TrendAlignment' && !empty($s['available']) && $s['direction'] !== 'NEUTRAL') {
                $alignmentPoints++;
                $facts[] = 'Strong timeframe alignment';
            }
            if ($s['source'] === 'Liquidity' && !empty($s['available']) && (float) $s['confidence'] >= 70.0) {
                $alignmentPoints++;
                $facts[] = 'High liquidity';
            }
            if ($s['source'] === 'Calendar' && empty($s['is_blocking'])) {
                $facts[] = 'No major news conflicts';
            }
        }

        $facts[] = round($factor) . '/100 historical calibration factor (' . ($calibration['well_calibrated'] ? 'well-calibrated' : ($factor < 50 ? 'historically overstates confidence' : 'historically understates confidence')) . ')';

        $grade = match (true) {
            $factor >= 70 && $sampleSize >= 30 && $alignmentPoints >= 2 => 'A',
            $factor >= 55 && $sampleSize >= 15 => 'B',
            $factor >= 40 => 'C',
            default => 'D',
        };

        return ['grade' => $grade, 'facts' => $facts, 'sample_size' => $sampleSize, 'insufficient_data' => false];
    }

    // ── Objectives 5/8: personal insights & lessons ──────────────────────────

    /**
     * Computed live from already-loaded TradeJournal::stats() — no AI call,
     * no persistence needed (cheap to recompute on every Learning Dashboard
     * load). Every lesson names its own sample size; a pattern with too
     * little data to say something real about is simply not returned.
     *
     * @return array<int, array{lesson:string, evidence:string, category:string}>
     */
    public function personalInsights(): array
    {
        $lessons = [];
        try {
            $stats = TradeJournal::stats();
        } catch (Throwable $e) {
            error_log('[AiTradingCoachService] personalInsights failed: ' . $e->getMessage());
            return [];
        }

        // Recurring mistakes.
        foreach ($stats['mistake_tag_counts'] as $tag => $count) {
            if ($count < 3) continue;
            $pct = $stats['closed_count'] > 0 ? round($count / $stats['closed_count'] * 100) : 0;
            $lessons[] = [
                'lesson'   => 'Recurring mistake: ' . MistakeTagCatalog::label((string) $tag) . '.',
                'evidence' => "Logged on {$count} of {$stats['closed_count']} closed trades ({$pct}%).",
                'category' => 'mistake_pattern',
            ];
        }

        // Session performance gaps.
        $overallWinRate = (float) $stats['win_rate'];
        foreach ($stats['by_session'] as $row) {
            $total = (int) $row['total'];
            if ($total < self::MIN_SAMPLE_FOR_LESSON) continue;
            $sessionWinRate = round(((int) $row['wins']) / $total * 100, 1);
            $gap = $sessionWinRate - $overallWinRate;
            if (abs($gap) < 15.0) continue;
            $sessionLabel = ucfirst(str_replace('_', ' ', (string) $row['session_name']));
            $lessons[] = [
                'lesson'   => $gap > 0
                    ? "Your {$sessionLabel} trades outperform your overall average."
                    : "You tend to underperform during {$sessionLabel} trades.",
                'evidence' => "{$sessionWinRate}% win rate across {$total} {$sessionLabel} trades vs {$overallWinRate}% overall.",
                'category' => 'session_performance',
            ];
        }

        // Pair performance gaps.
        foreach ($stats['by_market'] as $row) {
            $total = (int) $row['total'];
            if ($total < self::MIN_SAMPLE_FOR_LESSON) continue;
            $marketWinRate = round(((int) $row['wins']) / $total * 100, 1);
            $gap = $marketWinRate - $overallWinRate;
            if (abs($gap) < 15.0) continue;
            $lessons[] = [
                'lesson'   => $gap > 0
                    ? "{$row['market_label']} is one of your stronger pairs."
                    : "{$row['market_label']} tends to be a weaker pair for you.",
                'evidence' => "{$marketWinRate}% win rate across {$total} {$row['market_label']} trades vs {$overallWinRate}% overall.",
                'category' => 'pair_performance',
            ];
        }

        return $lessons;
    }

    // ── Objective 10: achievements ────────────────────────────────────────

    /** @return array<int, array{key:string, label:string, description:string, unlocked:bool, unlocked_at:?string, progress:float}> */
    public function achievements(): array
    {
        try {
            $stats = TradeJournal::stats();
            $closedCount = (int) $stats['closed_count'];
        } catch (Throwable) {
            $closedCount = 0;
        }

        $visionCount = 0;
        $decisionCount = 0;
        try {
            $visionCount = (int) \App\Core\Database::connection()->query('SELECT COUNT(*) FROM vision_sessions')->fetchColumn();
            $decisionCount = (int) \App\Core\Database::connection()->query('SELECT COUNT(*) FROM consensus_decisions')->fetchColumn();
        } catch (Throwable $e) {
            error_log('[AiTradingCoachService] achievements count query failed: ' . $e->getMessage());
        }
        $aiAnalysesCount = $visionCount + $decisionCount;

        $disciplinedStreak = $this->currentDisciplinedStreak();

        $definitions = [
            ['key' => 'trades_reviewed_10',  'label' => '10 Trades Reviewed',  'description' => 'Closed and logged 10 trades.', 'value' => $closedCount, 'threshold' => 10],
            ['key' => 'trades_reviewed_50',  'label' => '50 Trades Reviewed',  'description' => 'Closed and logged 50 trades.', 'value' => $closedCount, 'threshold' => 50],
            ['key' => 'trades_reviewed_100', 'label' => '100 Trades Reviewed', 'description' => 'Closed and logged 100 trades.', 'value' => $closedCount, 'threshold' => 100],
            ['key' => 'ai_analyses_100',     'label' => '100 AI Analyses Completed', 'description' => 'Combined AI Vision sessions and Decision Intelligence recommendations.', 'value' => $aiAnalysesCount, 'threshold' => 100],
            ['key' => 'disciplined_streak_10', 'label' => '10 Consecutive Disciplined Trades', 'description' => '10 closed trades in a row with zero logged mistakes.', 'value' => $disciplinedStreak, 'threshold' => 10],
        ];

        $result = [];
        $unlocked = Achievement::allUnlocked();
        foreach ($definitions as $def) {
            $isUnlocked = $def['value'] >= $def['threshold'];
            if ($isUnlocked && !isset($unlocked[$def['key']])) {
                Achievement::unlock($def['key'], ['value_at_unlock' => $def['value']]);
                $unlocked = Achievement::allUnlocked();
            }
            $result[] = [
                'key'         => $def['key'],
                'label'       => $def['label'],
                'description' => $def['description'],
                'unlocked'    => $isUnlocked,
                'unlocked_at' => $unlocked[$def['key']]['unlocked_at'] ?? null,
                'progress'    => min(1.0, $def['threshold'] > 0 ? $def['value'] / $def['threshold'] : 0.0),
            ];
        }

        return $result;
    }

    private function currentDisciplinedStreak(): int
    {
        try {
            $trades = TradeJournal::closedTradesChronological(200);
        } catch (Throwable) {
            return 0;
        }

        $streak = 0;
        for ($i = \count($trades) - 1; $i >= 0; $i--) {
            $tags = json_decode((string) ($trades[$i]['mistake_tags_json'] ?? '[]'), true);
            if (\is_array($tags) && $tags !== []) {
                break;
            }
            $streak++;
        }
        return $streak;
    }

    // ── Objective 7: Learning Dashboard metrics ──────────────────────────────

    /**
     * The metrics from this phase's spec that App\Learning\LearningEngine's
     * existing overview doesn't already compute (that page already covers
     * confidence calibration, strategy/pattern backtest stats, and
     * recommendation history — see class docblock precedent from the prior
     * two phases: extend real gaps, don't rebuild what's already there).
     * "Win rate by timeframe" is deliberately absent — trade_journal has no
     * timeframe column anywhere in this schema, and this method will not
     * invent one.
     *
     * @return array{
     *   improvement_trend: ?array{earlier_win_rate:float, recent_win_rate:float, earlier_count:int, recent_count:int},
     *   most_profitable_session: ?array, most_profitable_pair: ?array,
     *   weakest_habit: ?array{tag:string, label:string, count:int},
     *   avg_holding_period_hours: ?float,
     *   avg_profit_loss: float,
     *   ai_recommendation_adherence: ?array{matched:int, total:int, percent:float},
     * }
     */
    public function learningDashboardMetrics(): array
    {
        try {
            $stats = TradeJournal::stats();
        } catch (Throwable $e) {
            error_log('[AiTradingCoachService] learningDashboardMetrics failed: ' . $e->getMessage());
            return [
                'improvement_trend' => null, 'most_profitable_session' => null, 'most_profitable_pair' => null,
                'weakest_habit' => null, 'avg_holding_period_hours' => null, 'avg_profit_loss' => 0.0,
                'ai_recommendation_adherence' => null,
            ];
        }

        $trades = TradeJournal::closedTradesChronological(200);

        // Improvement trend: win rate in the first half of the trader's
        // history vs the second half — needs a real sample on both sides.
        $improvementTrend = null;
        if (\count($trades) >= self::MIN_SAMPLE_FOR_LESSON * 2) {
            $mid = (int) floor(\count($trades) / 2);
            $earlier = \array_slice($trades, 0, $mid);
            $recent  = \array_slice($trades, $mid);
            $winRateOf = static function (array $rows): float {
                $wins = \count(array_filter($rows, static fn(array $r): bool => $r['outcome'] === 'win'));
                $decided = \count(array_filter($rows, static fn(array $r): bool => \in_array($r['outcome'], ['win', 'loss'], true)));
                return $decided > 0 ? round($wins / $decided * 100, 1) : 0.0;
            };
            $improvementTrend = [
                'earlier_win_rate' => $winRateOf($earlier),
                'recent_win_rate'  => $winRateOf($recent),
                'earlier_count'    => \count($earlier),
                'recent_count'     => \count($recent),
            ];
        }

        $mostProfitableSession = $this->mostProfitable($stats['by_session'], 'session_name');
        $mostProfitablePair    = $this->mostProfitable($stats['by_market'], 'market_label');

        $weakestHabit = null;
        if ($stats['mistake_tag_counts'] !== []) {
            $tag = array_key_first($stats['mistake_tag_counts']);
            $weakestHabit = ['tag' => $tag, 'label' => MistakeTagCatalog::label((string) $tag), 'count' => $stats['mistake_tag_counts'][$tag]];
        }

        $durations = [];
        foreach ($trades as $t) {
            if (!empty($t['created_at']) && !empty($t['closed_at'])) {
                $durations[] = (strtotime((string) $t['closed_at']) - strtotime((string) $t['created_at'])) / 3600;
            }
        }
        $avgHoldingHours = $durations !== [] ? round(array_sum($durations) / \count($durations), 1) : null;

        // AI recommendation adherence — explicitly approximate (see
        // reviewClosedTrade()'s docblock: no real FK links trade_journal to
        // consensus_decisions). Bounded to the most recent 50 closed trades
        // so this stays a fast, bounded number of lookups on a dashboard load.
        $recentForAdherence = \array_slice($trades, -50);
        $matched = 0; $checked = 0;
        foreach ($recentForAdherence as $t) {
            if (empty($t['market_label']) || empty($t['direction']) || empty($t['created_at'])) continue;
            $nearest = $this->nearestDecisionBefore((string) $t['market_label'], (string) $t['created_at']);
            if ($nearest === null || !\in_array($nearest['overall_decision'], ['BUY', 'SELL'], true)) continue;
            $checked++;
            if (strtoupper((string) $t['direction']) === $nearest['overall_decision']) {
                $matched++;
            }
        }
        $adherence = $checked >= self::MIN_SAMPLE_FOR_RELIABILITY
            ? ['matched' => $matched, 'total' => $checked, 'percent' => round($matched / $checked * 100, 1)]
            : null;

        return [
            'improvement_trend'           => $improvementTrend,
            'most_profitable_session'     => $mostProfitableSession,
            'most_profitable_pair'        => $mostProfitablePair,
            'weakest_habit'               => $weakestHabit,
            'avg_holding_period_hours'    => $avgHoldingHours,
            'avg_profit_loss'             => (float) $stats['avg_profit_loss'],
            'ai_recommendation_adherence' => $adherence,
        ];
    }

    private function mostProfitable(array $rows, string $labelKey): ?array
    {
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn(array $a, array $b): int => ((float) $b['total_profit_loss']) <=> ((float) $a['total_profit_loss']));
        $top = $rows[0];
        return (float) $top['total_profit_loss'] > 0 ? [
            'label'       => (string) $top[$labelKey],
            'profit_loss' => (float) $top['total_profit_loss'],
            'total'       => (int) $top['total'],
        ] : null;
    }
}
