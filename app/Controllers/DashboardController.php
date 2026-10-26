<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Learning\ConfidenceCalibrationService;
use App\MarketIntelligence\FearGreedCompositeCalculator;
use App\MarketIntelligence\MarketIntelligenceEngine;
use App\Models\AiSummary;
use App\Models\CalendarEvent;
use App\Models\ConsensusDecision;
use App\Models\CurrencyStrengthSnapshot;
use App\Models\JournalEntry;
use App\Models\MarketDataProviderLog;
use App\Models\MultiTimeframeAnalysis;
use App\Models\NewsItem;
use App\Models\PatternStatistic;
use App\Models\SignalEvent;
use App\Models\StrategyStatistic;
use App\Models\UploadedChart;
use App\Models\VisionSession;
use App\Services\AiTradingCoachService;
use App\Services\EconomicCalendarService;
use Throwable;

final class DashboardController extends Controller
{
    public function index(): void
    {
        require_login();

        $calendar       = new EconomicCalendarService();
        $calendarEvents = $calendar->getUpcomingEvents(now_eat(), 7);

        $signals         = SignalEvent::latest(20);
        $latestSummaries = AiSummary::latest(1);
        $aiSummary       = $latestSummaries[0] ?? null;
        $perfStats       = SignalEvent::stats();

        $signalIds = array_column($signals, 'id');
        $journals  = JournalEntry::indexedBySignalId($signalIds);

        // Load AI Insight and Entry Signals from file caches — no AI call on page load
        $aiInsight    = $this->loadJsonCache('ai_insight.json',    'insight');
        $entrySignals = $this->loadJsonCache('entry_signals.json', 'entries');
        $entrySignals = \is_array($entrySignals) ? $entrySignals : [];

        $visionEnabled = feature_enabled('AI_VISION');
        $sentiment     = $this->buildSentimentPanel();

        $this->view('dashboard/index', [
            'title'                   => 'Dashboard',
            'signals'                 => $signals,
            'news'                    => NewsItem::latest(12),
            'userName'                => $_SESSION['user_name'] ?? 'Admin',
            'calendarEvents'          => $calendarEvents,
            'aiSummary'               => $aiSummary,
            'perfStats'               => $perfStats,
            'journals'                => $journals,
            'aiInsight'               => $aiInsight,
            'entrySignals'            => $entrySignals,
            'marketDataStats'         => MarketDataProviderLog::statsByProvider(),
            'marketDataFallbackEvents'=> MarketDataProviderLog::recentFallbackEvents(5),
            'visionEnabled'           => $visionEnabled,
            'recentCharts'            => $visionEnabled ? UploadedChart::latest(6) : [],
            'recentVisionSessions'    => $visionEnabled ? VisionSession::recent(5) : [],
            'bestOpportunity'         => $this->findBestOpportunity($signals),
            'waitReason'              => $this->findWaitReason($entrySignals),
            'sentiment'               => $sentiment,
            'todayCalendarEvents'     => $this->findTodayCalendarEvents(),
            'latestMultiTimeframe'    => $this->findLatestMultiTimeframe(),
            'performancePanel'        => $this->buildPerformancePanel($signals, $perfStats),
            'consensus'               => $this->buildConsensusPanel(),
        ]);
    }

    /**
     * Reads PERSISTED Decision Intelligence recommendations
     * (App\Services\DecisionIntelligenceEngine, run by
     * cron/consensus_refresh.php every 30 minutes or on-demand via
     * AdminToolsController::refreshConsensus()) — this never computes a
     * recommendation live on a dashboard page load (2 AI calls per
     * configured market would make every page load slow and expensive),
     * matching the same "cron computes, dashboard reads" convention as
     * aiInsight/entrySignals above. Null when no refresh has ever run yet
     * (fresh install) — the view falls back to the existing bestOpportunity/
     * waitReason cards exactly as before, no regression.
     *
     * @return ?array{
     *   market_quality: array{score:float, stars:int, label:string, market_label:string}|null,
     *   trade_today: array{recommended:bool, market_label:?string, confidence:float, risk:string, reasons:array<string>}|null,
     *   best: array|null,
     *   stale: bool,
     * }
     */
    private function buildConsensusPanel(): ?array
    {
        $labels = array_column(configured_markets(), 'label');
        if ($labels === []) {
            return null;
        }

        $decisions = ConsensusDecision::latestForMarkets($labels);
        if ($decisions === []) {
            return null;
        }

        $decoded = [];
        foreach ($decisions as $label => $row) {
            $decoded[$label] = [
                'row'          => $row,
                'sources'      => json_decode((string) $row['sources_json'], true) ?: [],
                'disagreement' => json_decode((string) $row['disagreement_json'], true) ?: [],
                'trade_plan'   => json_decode((string) $row['trade_plan_json'], true),
                'conflicts'    => json_decode((string) ($row['conflicts_json'] ?? '[]'), true) ?: [],
                'invalidation' => json_decode((string) ($row['invalidation_conditions_json'] ?? '[]'), true) ?: [],
            ];
        }

        // Market Quality Score: the best-conditions market among configured
        // markets, regardless of direction — answers "is today a good day
        // to trade [something]", not "is EUR/USD specifically good."
        $bestQuality = null;
        foreach ($decoded as $label => $entry) {
            $score = (float) $entry['row']['market_quality_score'];
            if ($bestQuality === null || $score > $bestQuality['score']) {
                $bestQuality = ['score' => $score, 'market_label' => $label, 'row' => $entry['row']];
            }
        }

        $marketQuality = $bestQuality !== null ? [
            'score'        => $bestQuality['score'],
            'stars'        => max(1, min(5, (int) round($bestQuality['score'] / 20))),
            'label'        => $this->qualityLabel($bestQuality['score']),
            'market_label' => $bestQuality['market_label'],
        ] : null;

        // Best actionable opportunity: highest consensus_score among BUY/SELL decisions.
        $best = null;
        foreach ($decoded as $label => $entry) {
            $decisionType = (string) $entry['row']['overall_decision'];
            if (!\in_array($decisionType, ['BUY', 'SELL'], true)) {
                continue;
            }
            $score = (float) $entry['row']['consensus_score'];
            if ($best === null || $score > $best['consensus_score']) {
                $best = [
                    'market_label'         => $label,
                    'decision'             => $decisionType,
                    'consensus_score'      => $score,
                    'agreement_percentage' => (float) $entry['row']['agreement_percentage'],
                    'risk_level'           => (string) $entry['row']['risk_level'],
                    'sources'              => $entry['sources'],
                    'disagreement'         => $entry['disagreement'],
                    'trade_plan'           => $entry['trade_plan'],
                    'conflicts'            => $entry['conflicts'],
                    'invalidation'         => $entry['invalidation'],
                    'next_review_at'       => $entry['row']['next_review_at'] ?? null,
                    'generated_at'         => (string) $entry['row']['generated_at'],
                ];
            }
        }

        // When nothing is actionable, still surface WHY — Objective 7's "for
        // WAIT, explain exactly what the AI is waiting for" — sourced from
        // the highest-quality market's own decision (whichever pair is
        // closest to actionable), not a generic placeholder.
        $primaryForExplanation = $best ?? ($bestQuality !== null ? array_merge(
            ['market_label' => $bestQuality['market_label']],
            $decoded[$bestQuality['market_label']]
        ) : null);

        $tradeToday = [
            'recommended'    => $best !== null,
            'market_label'   => $best['market_label'] ?? null,
            'confidence'     => $best['consensus_score'] ?? 0.0,
            'risk'           => $best['risk_level'] ?? 'MEDIUM',
            'reasons'        => $best !== null
                ? array_map(static fn(array $s): string => $s['reasoning'], array_filter($best['sources'], static fn(array $s): bool => $s['direction'] === $best['decision'] && $s['available']))
                : ['No actionable setup across configured markets right now.'],
            'conflicts'      => $primaryForExplanation['conflicts'] ?? [],
            'invalidation'   => $primaryForExplanation['invalidation'] ?? [],
            'next_review_at' => $primaryForExplanation['row']['next_review_at'] ?? $primaryForExplanation['next_review_at'] ?? null,
            'reliability'    => $best !== null
                ? (new AiTradingCoachService())->reliabilityScore($best['market_label'], $best['sources'])
                : null,
        ];

        // Freshness: if every decision is older than the 30-minute cron
        // cadence's own freshness window, flag it so the view can say so
        // rather than silently showing a stale read as if it were live.
        $stale = true;
        foreach ($decoded as $entry) {
            if ((time() - strtotime((string) $entry['row']['generated_at'])) <= 35 * 60) {
                $stale = false;
                break;
            }
        }

        return [
            'market_quality' => $marketQuality,
            'trade_today'    => $tradeToday,
            'best'           => $best,
            'all'            => $decoded,
            'stale'          => $stale,
        ];
    }

    private function qualityLabel(float $score): string
    {
        return match (true) {
            $score >= 80 => 'Excellent Trading Conditions',
            $score >= 60 => 'Good Trading Conditions',
            $score >= 40 => 'Fair Trading Conditions',
            default      => 'Poor Trading Conditions',
        };
    }

    /**
     * Section 9 "Multi-Timeframe Panel" — reads the most recent STORED
     * analysis (multi_timeframe_analyses table) across configured markets.
     * Deliberately does not call MultiTimeframeDataFetcher/TrendAlignmentEngine
     * live: that path fetches 8 separate candle series synchronously with no
     * cache (confirmed in App\MultiTimeframe's own docblocks) and would be
     * far too slow for a dashboard page load. A row only exists once someone
     * has run the full analysis from /multi-timeframe — if none has, this
     * honestly returns null rather than fabricating one.
     */
    private function findLatestMultiTimeframe(): ?array
    {
        $labels = array_column(configured_markets(), 'label');
        if ($labels === []) {
            return null;
        }

        $latest = null;
        foreach (MultiTimeframeAnalysis::latestForMarkets($labels) as $row) {
            if ($latest === null || $row['created_at'] > $latest['created_at']) {
                $latest = $row;
            }
        }
        if ($latest === null) {
            return null;
        }

        $breakdown = json_decode((string) $latest['breakdown_json'], true);
        $conflicts = json_decode((string) ($latest['conflicts_json'] ?? '[]'), true);
        $latest['breakdown'] = \is_array($breakdown) ? $breakdown : [];
        $latest['conflicts'] = \is_array($conflicts) ? $conflicts : [];
        return $latest;
    }

    /**
     * Section 11 "Performance Dashboard". Every figure here is either a
     * direct read of existing data or a documented honest fallback — nothing
     * is invented when the underlying table is empty (e.g. pattern_statistics
     * has 0 rows until App\Learning\PatternLearningService has run; AI
     * Accuracy shows "insufficient data" rather than a fabricated number when
     * ConfidenceCalibrationService's sample_size is 0, which is exactly
     * what it returns when signal_events has no recorded outcomes yet).
     */
    private function buildPerformancePanel(array $signals, array $perfStats): array
    {
        $confidences = array_map(static fn(array $s): int => (int) $s['confidence'], $signals);
        $avgConfidence = $confidences !== [] ? round(array_sum($confidences) / count($confidences), 1) : null;

        $strategyStats = StrategyStatistic::latest(20);
        $backtestWinRates = array_map(static fn(array $s): float => (float) $s['win_rate'], $strategyStats);
        $backtestAccuracy = $backtestWinRates !== [] ? round(array_sum($backtestWinRates) / count($backtestWinRates), 1) : null;

        $rrValues = array_map(static fn(array $s): float => (float) $s['avg_r'], $strategyStats);
        $patternStats = PatternStatistic::latest(20);
        foreach ($patternStats as $p) {
            $rrValues[] = (float) $p['avg_r'];
        }
        $avgRiskReward = $rrValues !== [] ? round(array_sum($rrValues) / count($rrValues), 2) : null;

        $calibration = ConfidenceCalibrationService::calculateFromBands($perfStats['by_confidence'] ?? []);

        return [
            'signalsToday'     => count(SignalEvent::todaySignals()),
            'openOpportunities'=> count(SignalEvent::openPositions()),
            'avgConfidence'    => $avgConfidence,
            'backtestAccuracy' => $backtestAccuracy,
            'avgRiskReward'    => $avgRiskReward,
            'aiCalibration'    => $calibration,
            'successfulPatterns' => array_values(array_filter($patternStats, static fn(array $p): bool => (float) $p['win_rate'] >= 50.0)),
            'patternCount'     => count($patternStats),
        ];
    }

    /**
     * Section 7 "Display today's events only" — distinct from the existing
     * 7-day risk-window card above (which drives entry-blocking logic).
     * upcoming(2) covers a day-boundary/timezone margin; filtered down to
     * events whose scheduled_at falls on today's EAT calendar date.
     */
    private function findTodayCalendarEvents(): array
    {
        $todayDate = now_eat()->format('Y-m-d');
        return array_values(array_filter(
            CalendarEvent::upcoming(2),
            static fn(array $ev): bool => substr((string) ($ev['scheduled_at'] ?? ''), 0, 10) === $todayDate
        ));
    }

    /**
     * Section 5 "Market Sentiment" — reuses the exact same calculators
     * /market-intelligence already renders (CurrencyStrengthCalculator,
     * VolatilityIndexCalculator, SentimentIndexService, NewsImpactScorer),
     * just assembled for the dashboard. Independent, non-fatal: if the
     * underlying pair-snapshot fetch fails, the rest of the dashboard still
     * renders — this panel alone shows "unavailable".
     *
     * @return ?array{
     *   currencyStrength: array<string,float>, strongest: ?string, weakest: ?string,
     *   volatility: array, sentimentIndex: array, newsImpact: array,
     *   newsBullish: int, newsBearish: int, newsNeutral: int, fearGreed: array
     * }
     */
    private function buildSentimentPanel(): ?array
    {
        try {
            $snap = (new MarketIntelligenceEngine())->buildCached();

            $strength  = $snap->currencyStrength;
            $strongest = $strength !== [] ? array_key_first($strength) : null;
            $weakest   = $strength !== [] ? array_key_last($strength) : null;

            if ($strength !== [] && CurrencyStrengthSnapshot::shouldRecord()) {
                CurrencyStrengthSnapshot::recordAll($strength);
            }

            $history = [];
            foreach (array_keys($strength) as $currency) {
                $history[$currency] = CurrencyStrengthSnapshot::history($currency, 20);
            }

            $newsBullish = $newsBearish = $newsNeutral = 0;
            foreach ($snap->newsImpact['recent'] ?? [] as $item) {
                match (strtolower((string) ($item['market_bias'] ?? 'neutral'))) {
                    'bullish' => $newsBullish++,
                    'bearish' => $newsBearish++,
                    default   => $newsNeutral++,
                };
            }

            return [
                'currencyStrength' => $strength,
                'strongest'        => $strongest,
                'weakest'          => $weakest,
                'volatility'       => $snap->volatilityIndex,
                'sentimentIndex'   => $snap->sentimentIndex,
                'newsImpact'       => $snap->newsImpact,
                'newsBullish'      => $newsBullish,
                'newsBearish'      => $newsBearish,
                'newsNeutral'      => $newsNeutral,
                'fearGreed'        => FearGreedCompositeCalculator::calculate($snap->sentimentIndex, $snap->volatilityIndex),
                'history'          => $history,
            ];
        } catch (Throwable $e) {
            error_log('[DashboardController] Sentiment panel unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Highest-confidence open (no outcome yet) BUY/SELL from the already-
     * loaded recent signals — the Decision Center's "current best
     * opportunity". No new query: reuses the same 20-row set the Recent
     * Signals table renders, so the two never disagree.
     */
    private function findBestOpportunity(array $signals): ?array
    {
        $best = null;
        foreach ($signals as $sig) {
            $action = strtoupper((string) ($sig['action'] ?? ''));
            if (!\in_array($action, ['BUY', 'SELL'], true)) {
                continue;
            }
            if (($sig['outcome'] ?? null) !== null) {
                continue;
            }
            if ($best === null || (int) ($sig['confidence'] ?? 0) > (int) ($best['confidence'] ?? 0)) {
                $best = $sig;
            }
        }
        return $best;
    }

    /**
     * When there's no actionable signal, surface the real rule-engine reason
     * for the pair closest to actionable (highest confidence among WAIT
     * states) from the entry_signals.json cache — never a fabricated reason.
     */
    private function findWaitReason(array $entrySignals): ?array
    {
        $closest = null;
        foreach ($entrySignals as $pair => $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if ($closest === null || (int) ($entry['confidence'] ?? 0) > (int) ($closest['confidence'] ?? 0)) {
                $closest = $entry;
            }
        }
        return $closest;
    }

    private function loadJsonCache(string $filename, string $key): mixed
    {
        $path = base_path("storage/cache/{$filename}");
        if (!file_exists($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return \is_array($data) && isset($data[$key]) ? $data[$key] : null;
    }
}
