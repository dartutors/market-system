<?php
declare(strict_types=1);
namespace App\Services;

use App\Consensus\Adapters\CalendarVoteAdapter;
use App\Consensus\Adapters\ClaudeOpinionAdapter;
use App\Consensus\Adapters\CurrencyStrengthVoteAdapter;
use App\Consensus\Adapters\HistoricalAccuracyVoteAdapter;
use App\Consensus\Adapters\LiquidityVoteAdapter;
use App\Consensus\Adapters\NewsVoteAdapter;
use App\Consensus\Adapters\OpenAiOpinionAdapter;
use App\Consensus\Adapters\SessionVoteAdapter;
use App\Consensus\Adapters\SmartMoneyVoteAdapter;
use App\Consensus\Adapters\StrategyLabVoteAdapter;
use App\Consensus\Adapters\TechnicalVoteAdapter;
use App\Consensus\Adapters\TradeJournalLearningVoteAdapter;
use App\Consensus\Adapters\TrendAlignmentVoteAdapter;
use App\Consensus\Adapters\VisionVoteAdapter;
use App\Consensus\Adapters\VolatilityVoteAdapter;
use App\Consensus\ConflictDetector;
use App\Consensus\ConsensusScorer;
use App\Consensus\ConsensusVote;
use App\Consensus\MarketQualityScorer;
use App\Consensus\TradePlanBuilder;
use App\Models\ConsensusDecision;
use Throwable;

/**
 * The canonical decision-maker this app funnels every BUY/SELL/WAIT
 * recommendation through — evidence gathering, normalization, agreement/
 * conflict evaluation, and confidence scoring all happen here, not
 * independently in whichever module happens to render a card. No
 * fabricated confidence: every number below is either a weighted average of
 * real per-source confidences (ConsensusScorer), a real calibration factor
 * against actual historical outcomes (HistoricalAccuracyVoteAdapter), or an
 * explicit penalty tied to a named, explained conflict (ConflictDetector) —
 * never an arbitrary constant.
 *
 * Architecture note: this class is the evolution of App\Consensus\
 * ConsensusEngine (built in the immediately preceding phase of this same
 * project) — not a second, parallel "fuse everything" system. It reuses
 * that module's adapters, ConsensusScorer, MarketQualityScorer, and
 * TradePlanBuilder as its evidence-gathering machinery (Objective 1's
 * "collect all available evidence / normalize inputs" is exactly what
 * those already do, tested and working), and adds what was missing against
 * this phase's fuller spec: 5 more evidence sources (Smart Money Concepts,
 * Liquidity as distinct from Volatility, Strategy Lab, Historical Accuracy,
 * Trade Journal Learning), structured named conflict detection beyond
 * "these sources disagreed", confidence penalties for those conflicts,
 * invalidation conditions, a next-review timestamp, and outcome-based
 * performance tracking (see OutcomeResolver / DecisionPerformanceAnalyzer).
 * Two independent "true source of truth" engines would be a real
 * architecture smell — deliberately avoided rather than reinvented.
 *
 * Still NOT rewired into: cron/market_scan.php's signal_events pipeline
 * (App\Services\SignalEngineService), which continues to drive the
 * existing, already-certified email/Telegram alert flow unchanged. That
 * pipeline's technical signal is itself one of this engine's inputs
 * (source: "Technical"). Rerouting the alert-triggering pipeline itself
 * through this engine would be a materially larger, riskier change than
 * this phase's scope, and isn't required to satisfy "every recommendation
 * a user is SHOWN comes from this engine" — which is what's implemented.
 */
final class DecisionIntelligenceEngine
{
    /** Below this adjusted confidence, the recommendation is forced to WAIT regardless of the raw majority — weak/conflicting evidence must not produce an actionable call. */
    private const MIN_ACTIONABLE_CONFIDENCE = 55.0;

    private TechnicalVoteAdapter $technical;
    private ClaudeOpinionAdapter $claude;
    private OpenAiOpinionAdapter $openai;
    private VisionVoteAdapter $vision;
    private NewsVoteAdapter $news;
    private CalendarVoteAdapter $calendar;
    private CurrencyStrengthVoteAdapter $currencyStrength;
    private SessionVoteAdapter $session;
    private VolatilityVoteAdapter $volatility;
    private TrendAlignmentVoteAdapter $trendAlignment;
    private SmartMoneyVoteAdapter $smartMoney;
    private LiquidityVoteAdapter $liquidity;
    private StrategyLabVoteAdapter $strategyLab;
    private HistoricalAccuracyVoteAdapter $historicalAccuracy;
    private TradeJournalLearningVoteAdapter $tradeJournalLearning;
    private ConsensusScorer $scorer;
    private MarketQualityScorer $qualityScorer;
    private ConflictDetector $conflictDetector;
    private TradePlanBuilder $tradePlanBuilder;

    public function __construct()
    {
        $this->technical            = new TechnicalVoteAdapter();
        $this->claude               = new ClaudeOpinionAdapter();
        $this->openai               = new OpenAiOpinionAdapter();
        $this->vision               = new VisionVoteAdapter();
        $this->news                 = new NewsVoteAdapter();
        $this->calendar             = new CalendarVoteAdapter();
        $this->currencyStrength     = new CurrencyStrengthVoteAdapter();
        $this->session              = new SessionVoteAdapter();
        $this->volatility           = new VolatilityVoteAdapter();
        $this->trendAlignment       = new TrendAlignmentVoteAdapter();
        $this->smartMoney           = new SmartMoneyVoteAdapter();
        $this->liquidity            = new LiquidityVoteAdapter();
        $this->strategyLab          = new StrategyLabVoteAdapter();
        $this->historicalAccuracy   = new HistoricalAccuracyVoteAdapter();
        $this->tradeJournalLearning = new TradeJournalLearningVoteAdapter();
        $this->scorer               = new ConsensusScorer();
        $this->qualityScorer        = new MarketQualityScorer();
        $this->conflictDetector     = new ConflictDetector();
        $this->tradePlanBuilder     = new TradePlanBuilder();
    }

    /**
     * Gathers evidence, scores it, detects conflicts, adjusts confidence,
     * builds a trade plan when actionable, and persists the full decision
     * record. This is the ONLY method that should ever be called to
     * produce a recommendation shown to a user.
     */
    public function decide(string $symbol, string $label): array
    {
        $technicalRaw  = $this->technical->evaluateRaw($symbol, $label);
        $technicalVote = $technicalRaw !== null ? $this->technical->voteFromRaw($technicalRaw) : $this->fallback('Technical', TechnicalVoteAdapter::WEIGHT);

        // Cheap, non-AI votes first — also the context bundle the two AI
        // opinion adapters read from (see AbstractAiOpinionAdapter).
        $contextVotes = [
            'Technical'        => $technicalVote,
            'News'             => $this->news->vote($symbol, $label),
            'Calendar'         => $this->calendar->vote($symbol, $label),
            'CurrencyStrength' => $this->currencyStrength->vote($symbol, $label),
            'Session'          => $this->session->vote($symbol, $label),
            'Volatility'       => $this->volatility->vote($symbol, $label),
        ];

        $votes = array_values($contextVotes);
        $votes[] = $this->trendAlignment->vote($symbol, $label);
        $votes[] = $this->vision->vote($symbol, $label);
        $votes[] = $this->smartMoney->vote($symbol, $label);
        $votes[] = $this->liquidity->vote($symbol, $label);
        $votes[] = $this->strategyLab->vote($symbol, $label);
        $votes[] = $this->historicalAccuracy->vote($symbol, $label);
        $votes[] = $this->tradeJournalLearning->vote($symbol, $label);
        $votes[] = $this->claude->voteWithContext($label, $contextVotes);
        $votes[] = $this->openai->voteWithContext($label, $contextVotes);

        $decision = $this->scorer->score($votes);
        $conflicts = $this->conflictDetector->detect($votes);

        [$adjustedScore, $adjustmentNotes] = $this->applyConfidenceAdjustments(
            $decision['consensus_score'],
            $conflicts,
            $votes
        );

        $overallDecision = $decision['overall_decision'] === ConsensusVote::DIRECTION_NEUTRAL
            ? 'WAIT'
            : $decision['overall_decision'];

        // Weak/conflicting evidence must not produce an actionable call,
        // even if the raw vote tally leaned BUY/SELL — Objective 5/"must
        // never fabricate confidence or certainty".
        $forcedToWait = false;
        if ($overallDecision !== 'WAIT' && $adjustedScore < self::MIN_ACTIONABLE_CONFIDENCE) {
            $forcedToWait = true;
            $overallDecision = 'WAIT';
        }

        $quality = $this->qualityScorer->score($votes, $decision['agreement_percentage']);
        $riskLevel = $this->deriveRiskLevel($decision, $conflicts, $votes);

        $tradePlan = ($technicalRaw !== null && !$forcedToWait)
            ? $this->tradePlanBuilder->build($label, $decision['overall_decision'] === ConsensusVote::DIRECTION_NEUTRAL ? 'WAIT' : $decision['overall_decision'], $technicalRaw, $adjustedScore)
            : null;
        // TradePlanBuilder itself refuses anything but BUY/SELL — this
        // double-guards the forced-WAIT case explicitly rather than relying
        // solely on that internal check, since $decision['overall_decision']
        // (pre-adjustment) could still say BUY/SELL here.
        if ($overallDecision === 'WAIT') {
            $tradePlan = null;
        }

        $invalidationConditions = $this->buildInvalidationConditions($overallDecision, $tradePlan, $votes, $conflicts);
        $nextReviewAt = $this->nextReviewTime($overallDecision, $votes, $conflicts);

        // Reliability, per Phase 24's "for every recommendation, store...
        // Reliability" — computed the same way the dashboard's best-opportunity
        // card already does (AiTradingCoachService::reliabilityScore(), which
        // reuses ConfidenceCalibrationService rather than a second calibration
        // implementation), just persisted here instead of only on-demand.
        $reliability = ['grade' => 'N/A', 'sample_size' => 0];
        try {
            $reliability = (new AiTradingCoachService())->reliabilityScore(
                $label,
                array_map(static fn(ConsensusVote $v): array => $v->toArray(), $votes)
            );
        } catch (Throwable $e) {
            error_log("[DecisionIntelligenceEngine] reliabilityScore failed for {$label}: " . $e->getMessage());
        }

        $result = [
            'market_label'            => $label,
            'market_symbol'           => $symbol,
            'overall_decision'        => $overallDecision,
            'consensus_score'         => $adjustedScore,
            'raw_consensus_score'     => $decision['consensus_score'],
            'agreement_percentage'    => $decision['agreement_percentage'],
            'market_quality_score'    => $quality['score'],
            'market_quality_stars'    => $quality['stars'],
            'market_quality_label'    => $quality['label'],
            'risk_level'              => $riskLevel,
            'sources'                 => array_map(static fn(ConsensusVote $v): array => $v->toArray(), $votes),
            'disagreement_reasons'    => $decision['disagreement_reasons'],
            'conflicts'               => $conflicts,
            'confidence_adjustments'  => $adjustmentNotes,
            'blocked_reason'          => $decision['blocked_reason'],
            'forced_to_wait'          => $forcedToWait,
            'trade_plan'              => $tradePlan,
            'invalidation_conditions' => $invalidationConditions,
            'next_review_at'          => $nextReviewAt,
            'reliability_grade'       => $reliability['grade'],
            'reliability_sample_size' => $reliability['sample_size'],
        ];

        $this->persist($result);

        return $result;
    }

    /** @param array<int, array{symbol:string, label:string}> $markets @return array<string, array> keyed by label */
    public function decideAll(array $markets): array
    {
        $results = [];
        foreach ($markets as $market) {
            try {
                $results[$market['label']] = $this->decide($market['symbol'], $market['label']);
            } catch (Throwable $e) {
                error_log("[DecisionIntelligenceEngine] decide() failed for {$market['label']}: " . $e->getMessage());
            }
        }
        return $results;
    }

    /**
     * @param array<int, array{type:string, description:string, severity:string}> $conflicts
     * @param ConsensusVote[] $votes
     * @return array{0: float, 1: array<int, string>}
     */
    private function applyConfidenceAdjustments(float $baseScore, array $conflicts, array $votes): array
    {
        $notes = [];
        $score = $baseScore;

        // Named conflicts are always listed, even against a 0 base score —
        // they're real, detected, and explain WHY the score is low/zero,
        // which matters for Objective 6's "no unexplained recommendations"
        // even when there's no positive confidence left to subtract from.
        foreach ($conflicts as $conflict) {
            $penalty = $conflict['severity'] === 'high' ? 15.0 : 8.0;
            $score -= $penalty;
            $notes[] = "-{$penalty} pts: {$conflict['description']}";
        }

        if ($baseScore <= 0.0) {
            return [0.0, $notes];
        }

        // Historical calibration: a system that has historically overstated
        // confidence gets its current confidence tempered; one that's
        // understated it gets a small boost — bounded so a single factor
        // can't swing the score wildly.
        $historical = $this->findVote($votes, 'HistoricalAccuracy');
        if ($historical !== null && $historical->available) {
            $multiplier = max(0.75, min(1.15, $historical->confidence / 50.0));
            if (abs($multiplier - 1.0) > 0.01) {
                $before = $score;
                $score *= $multiplier;
                $notes[] = sprintf('%s%.1f pts: historical calibration factor %.0f/100 (%s)', $score >= $before ? '+' : '', $score - $before, $historical->confidence, $historical->reasoning);
            }
        }

        // Trader's own journal history on this pair: a small nudge, not a
        // dominant factor — this is one trader's sample, not a system-wide
        // calibration.
        $journal = $this->findVote($votes, 'TradeJournalLearning');
        if ($journal !== null && $journal->available) {
            $delta = ($journal->confidence - 50.0) * 0.1; // max ±5 points
            if (abs($delta) >= 0.5) {
                $score += $delta;
                $notes[] = sprintf('%s%.1f pts: %s', $delta >= 0 ? '+' : '', $delta, $journal->reasoning);
            }
        }

        return [round(max(0.0, min(100.0, $score)), 1), $notes];
    }

    /**
     * @param ConsensusVote[] $votes
     * @param array<int, array{type:string, description:string, severity:string}> $conflicts
     * @return array<int, string>
     */
    private function buildInvalidationConditions(string $decision, ?array $tradePlan, array $votes, array $conflicts): array
    {
        if ($decision === 'BUY' || $decision === 'SELL') {
            $conditions = [];
            if ($tradePlan !== null) {
                $conditions[] = ($decision === 'BUY' ? 'Price closes below' : 'Price closes above')
                    . ' the stop level (' . $tradePlan['stop_loss'] . ') — the technical thesis behind this trade no longer holds.';
            }
            $calendar = $this->findVote($votes, 'Calendar');
            if ($calendar !== null && !$calendar->isBlocking) {
                $conditions[] = 'A new high-impact economic event enters its risk window for this pair before the trade resolves.';
            }
            $trend = $this->findVote($votes, 'TrendAlignment');
            if ($trend !== null && $trend->available && $trend->direction === $decision) {
                $conditions[] = 'The higher-timeframe bias (currently ' . strtolower($trend->direction) . ') flips against this direction.';
            }
            return $conditions;
        }

        // WAIT: explain exactly what's being waited for (Objective 7).
        $waitingFor = [];
        foreach ($conflicts as $conflict) {
            $waitingFor[] = 'Resolution of: ' . $conflict['description'];
        }
        if ($waitingFor === []) {
            $unavailable = array_filter($votes, static fn(ConsensusVote $v): bool => $v->isDirectional && !$v->available);
            foreach ($unavailable as $v) {
                $waitingFor[] = "More data from {$v->source} ({$v->reasoning})";
            }
        }
        if ($waitingFor === []) {
            $waitingFor[] = 'Stronger agreement across sources — evidence is currently too weak or mixed to act on confidently.';
        }
        return $waitingFor;
    }

    /** @param ConsensusVote[] $votes @param array<int, array{type:string, description:string, severity:string}> $conflicts */
    private function nextReviewTime(string $decision, array $votes, array $conflicts): string
    {
        $now = now_eat();

        // Blocked by an active calendar window — review once it ends (best estimate: 45 min).
        foreach ($conflicts as $conflict) {
            if ($conflict['type'] === 'calendar_risk_window') {
                return $now->modify('+45 minutes')->format('Y-m-d H:i:s');
            }
        }

        // Waiting on a session — review at the next session's start.
        if ($decision === 'WAIT') {
            $session = $this->findVote($votes, 'Session');
            if ($session !== null && $session->confidence < 30.0) {
                try {
                    $countdown = (new TradingSessionService())->nextSessionCountdown($now);
                    if ($countdown['minutes_until'] !== null) {
                        return $now->modify('+' . $countdown['minutes_until'] . ' minutes')->format('Y-m-d H:i:s');
                    }
                } catch (Throwable) {
                    // fall through to default
                }
            }
        }

        // Default: matches this engine's own refresh cadence.
        return $now->modify('+30 minutes')->format('Y-m-d H:i:s');
    }

    private function deriveRiskLevel(array $decision, array $conflicts, array $votes): string
    {
        if ($decision['blocked_reason'] !== null || $this->hasHighSeverityConflict($conflicts)) {
            return 'HIGH';
        }

        $volatilityVote = $this->findVote($votes, 'Volatility');
        if ($volatilityVote !== null && str_starts_with(strtolower($volatilityVote->reasoning), 'high')) {
            return 'HIGH';
        }

        if (\count($conflicts) > 0) {
            return 'MEDIUM';
        }

        return $decision['agreement_percentage'] >= 75.0 ? 'LOW' : 'MEDIUM';
    }

    private function hasHighSeverityConflict(array $conflicts): bool
    {
        foreach ($conflicts as $c) {
            if ($c['severity'] === 'high') {
                return true;
            }
        }
        return false;
    }

    /** @param ConsensusVote[] $votes */
    private function findVote(array $votes, string $source): ?ConsensusVote
    {
        foreach ($votes as $vote) {
            if ($vote->source === $source) {
                return $vote;
            }
        }
        return null;
    }

    private function fallback(string $source, float $weight): ConsensusVote
    {
        return new ConsensusVote($source, ConsensusVote::DIRECTION_NEUTRAL, 0.0, $weight, 'Data unavailable right now.', false);
    }

    private function persist(array $result): void
    {
        try {
            ConsensusDecision::create([
                'market_label'         => $result['market_label'],
                'market_symbol'        => $result['market_symbol'],
                'overall_decision'     => $result['overall_decision'],
                'consensus_score'      => $result['consensus_score'],
                'agreement_percentage' => $result['agreement_percentage'],
                'market_quality_score' => $result['market_quality_score'],
                'risk_level'           => $result['risk_level'],
                'sources_json'         => json_encode($result['sources'], JSON_UNESCAPED_UNICODE),
                'disagreement_json'    => json_encode(['reasons' => $result['disagreement_reasons'], 'blocked_reason' => $result['blocked_reason']], JSON_UNESCAPED_UNICODE),
                'conflicts_json'       => json_encode($result['conflicts'], JSON_UNESCAPED_UNICODE),
                'trade_plan_json'      => json_encode($result['trade_plan'], JSON_UNESCAPED_UNICODE),
                'invalidation_conditions_json' => json_encode($result['invalidation_conditions'], JSON_UNESCAPED_UNICODE),
                'next_review_at'       => $result['next_review_at'],
                'reliability_grade'         => $result['reliability_grade'],
                'reliability_sample_size'   => $result['reliability_sample_size'],
            ]);
        } catch (Throwable $e) {
            error_log("[DecisionIntelligenceEngine] Failed to persist decision for {$result['market_label']}: " . $e->getMessage());
        }
    }
}
