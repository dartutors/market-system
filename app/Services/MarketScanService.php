<?php
declare(strict_types=1);
namespace App\Services;

use App\Decision\DecisionEngine;
use App\Domain\MarketSnapshotBuilder;
use App\Models\AiDecision;
use App\Models\AiSummary;
use App\Models\NewsItem;
use App\Models\SignalEvent;
use App\Patterns\PatternRecognitionEngine;
use Throwable;

final class MarketScanService
{
    public function run(): array
    {
        $logger       = new ScanLoggerService();
        $indicators   = new IndicatorService();
        $marketData   = new MarketDataService();
        $newsService  = new NewsService();
        $sentiment    = new SentimentService();
        $engine       = new SignalEngineService();
        $notifier     = new NotificationService();
        $telegram     = new TelegramNotifier();
        $calendar     = new EconomicCalendarService();
        $entryService = new EntrySignalService();
        $limiter      = new TradeLimiterService();

        $markets = configured_markets();

        // ── Fetch news once; build sentiment score + store articles ─────────
        $newsResult       = $this->buildSentimentSummary($newsService, $sentiment);
        $sentimentSummary = $newsResult;
        $articles         = $newsResult['articles'] ?? [];

        // ── Load latest AI bias for entry alignment checks (from cache/DB) ──
        $latestAiRows = AiSummary::latest(1);
        $aiBias       = strtolower((string) ($latestAiRows[0]['market_bias'] ?? 'mixed'));
        $aiConf       = (float) ($latestAiRows[0]['confidence']              ?? 50.0);

        // ── Today's trade count (global, across all markets) ─────────────────
        $now        = now_eat();
        $todayCount = $limiter->todayCount($now->format('Y-m-d'));

        // ── Scan each market independently ───────────────────────────────────
        $summaryParts      = [];
        $marketResultsList = [];
        $entryResults      = [];
        $decisionResults   = [];

        foreach ($markets as $market) {
            try {
                $result = $this->scanMarket(
                    $market['symbol'],
                    $market['label'],
                    $sentimentSummary,
                    $logger,
                    $indicators,
                    $marketData,
                    $engine,
                    $notifier,
                    $telegram,
                    $calendar,
                    $entryService,
                    $aiBias,
                    $aiConf,
                    $todayCount
                );
                $summaryParts[]                    = $this->formatSummaryPart($market['label'], $result);
                $marketResultsList[]               = $result;
                $entryResults[$market['label']]    = $result['entry'] ?? [];
                $decisionResults[$market['label']] = $result['decision'] ?? null;
            } catch (\Throwable $e) {
                $logger->log('error', $e->getMessage(), $market['symbol']);
                $summaryParts[]      = $market['label'] . ': ERROR — ' . $e->getMessage();
                $marketResultsList[] = ['market' => $market['label'], 'action' => 'ERROR',
                                        'message' => $e->getMessage()];
                $entryResults[$market['label']]    = [];
                $decisionResults[$market['label']] = null;
            }
        }

        // ── One AI news summary per scan cycle ───────────────────────────────
        if (!empty($articles)) {
            try {
                (new AiSummaryService())->summarize($articles, $marketResultsList);
            } catch (\Throwable $e) {
                error_log('[MarketScanService] AI summary error: ' . $e->getMessage());
            }
        }

        // ── Unified AI Insight ────────────────────────────────────────────────
        try {
            $stats          = SignalEvent::stats();
            $upcomingEvents = $calendar->getUpcomingEvents(now_eat(), 7);
            $latestSummary  = AiSummary::latest(1);
            $summaryData    = $latestSummary[0] ?? [];

            (new AiInsightService())->generate($stats, $upcomingEvents, $summaryData);
        } catch (\Throwable $e) {
            error_log('[MarketScanService] AI insight error: ' . $e->getMessage());
        }

        // ── Cache entry signals to file ──────────────────────────────────────
        try {
            $cacheDir = storage_path('cache');
            if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

            $payload = json_encode([
                'scan_time' => date('Y-m-d H:i:s'),
                'entries'   => $entryResults,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if ($payload !== false) {
                @file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'entry_signals.json', $payload, LOCK_EX);
            }
        } catch (\Throwable $e) {
            error_log('[MarketScanService] Entry cache write error: ' . $e->getMessage());
        }

        // ── Cache unified decisions to file (mirrors entry_signals.json above) ─
        try {
            $cacheDir = storage_path('cache');
            if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

            $payload = json_encode([
                'scan_time' => date('Y-m-d H:i:s'),
                'decisions' => $decisionResults,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if ($payload !== false) {
                @file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'ai_decisions.json', $payload, LOCK_EX);
            }
        } catch (\Throwable $e) {
            error_log('[MarketScanService] Decision cache write error: ' . $e->getMessage());
        }

        $count   = \count($markets);
        $details = implode(' | ', $summaryParts);

        $result = [
            'status'  => 'ok',
            'message' => 'Scan completed for ' . $count . ' ' . ($count === 1 ? 'market' : 'markets') . '.',
            'details' => $details,
        ];

        // ── Domain event (additive) — fires after the return value above is
        //    already computed; does not affect it. ────────────────────────────
        try {
            \App\Events\EventDispatcher::dispatch(new \App\Events\MarketScanCompleted($result, now_eat()));
        } catch (\Throwable $e) {
            error_log('[MarketScanService] Event dispatch error: ' . $e->getMessage());
        }

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildSentimentSummary(NewsService $newsService, SentimentService $sentiment): array
    {
        $scores   = [];
        $articles = [];

        try {
            $articles = $newsService->fetchHeadlines();
        } catch (\Throwable) {
            return ['score' => 0.0, 'articles' => []];
        }

        foreach ($articles as $article) {
            $analysis = $sentiment->analyze((string) $article['title']);
            $scores[] = ((float) $analysis['polarity']) * ((float) $analysis['impact']);

            if (!NewsItem::existsByTitle((string) $article['title'])) {
                NewsItem::create([
                    'title'         => (string) $article['title'],
                    'source'        => (string) $article['source'],
                    'url'           => (string) $article['url'],
                    'published_at'  => ($article['published_at'] ?? '') !== ''
                                       ? (string) $article['published_at'] : null,
                    'raw_sentiment' => (float) $analysis['polarity'],
                    'impact_score'  => (float) $analysis['impact'],
                    'market_bias'   => (string) $analysis['bias'],
                    'provider'      => (string) ($article['provider'] ?? 'unknown'),
                ]);
            }

            $article['market_bias']   = (string) $analysis['bias'];
            $article['raw_sentiment'] = (float)  $analysis['polarity'];
        }

        return [
            'score'    => \count($scores) > 0 ? array_sum($scores) / \count($scores) : 0.0,
            'articles' => $articles,
        ];
    }

    private function scanMarket(
        string $symbol,
        string $label,
        array $sentimentSummary,
        ScanLoggerService $logger,
        IndicatorService $indicators,
        MarketDataService $marketData,
        SignalEngineService $engine,
        NotificationService $notifier,
        TelegramNotifier $telegram,
        EconomicCalendarService $calendar,
        EntrySignalService $entryService,
        string $aiBias,
        float  $aiConf,
        int    $todayCount
    ): array {
        $now        = now_eat();
        $signalDate = $now->format('Y-m-d');

        // ── Per-market daily cap ───────────────────────────────────────────────
        $risk = new RiskGuardService();
        [$canEmit, $reason] = $risk->canEmit($label, $signalDate, $now);
        if (!$canEmit) {
            $logger->log('skipped', $label . ': ' . $reason, $symbol);
            return ['status' => 'skipped', 'market' => $label, 'message' => $reason,
                    'entry' => $this->buildSkippedEntry($label, $reason)];
        }

        // ── Market data + indicators ──────────────────────────────────────────
        $rows   = $indicators->enrich($marketData->fetchCandles($symbol));
        $signal = $engine->evaluate($rows, $sentimentSummary);

        // ── Chart pattern recognition (Phase 2.9, additive-only) ──────────────
        // Appends human-readable findings to $signal['reasons']; never touches
        // $signal['action']/['confidence'] — a bad or slow detector degrades to
        // "no patterns reported" (try/catch below), it can never change what
        // the Scanner decides or block a signal from being emitted.
        if ((bool) env('USE_PATTERN_RECOGNITION', true)) {
            try {
                foreach ((new PatternRecognitionEngine())->detectAboveConfidence($rows, 60.0) as $match) {
                    $signal['reasons'][] = sprintf(
                        'Chart pattern detected: %s (%s, confidence %d%%).',
                        $match->label,
                        $match->direction,
                        (int) round($match->confidence)
                    );
                }
            } catch (Throwable $e) {
                error_log('[MarketScanService] Pattern recognition skipped: ' . $e->getMessage());
            }
        }

        // ── Calendar risk (computed early — used for both entry eval + hard block)
        $calendarRisk = $calendar->getActiveRiskWindow($label, $now);

        // ── Watch Mode post-processing ────────────────────────────────────────
        if (\in_array($signal['action'], ['WAIT', 'HOLD'], true) && (float) $signal['confidence'] >= 50.0) {
            $count    = \count($rows);
            $latest   = $rows[$count - 1];
            $prev     = $rows[$count - 2];
            $rsi      = (float) ($latest['rsi_14']    ?? 50.0);
            $mHist    = (float) ($latest['macd_hist'] ?? 0.0);
            $pHist    = (float) ($prev['macd_hist']   ?? 0.0);
            $watchNote = null;

            if ($rsi <= 38 && $rsi > 30) {
                $watchNote = 'RSI approaching oversold zone (' . round($rsi, 1) . '). BUY setup may develop.';
            } elseif ($rsi >= 62 && $rsi < 70) {
                $watchNote = 'RSI approaching overbought zone (' . round($rsi, 1) . '). SELL setup may develop.';
            } elseif ($pHist !== 0.0 && abs($mHist) < abs($pHist) * 0.35 && abs($mHist) < 0.0002) {
                $watchNote = 'MACD histogram converging — crossover may be imminent.';
            }

            if ($watchNote !== null) {
                $signal['action']    = 'WATCH';
                $signal['reasons'][] = 'WATCH: ' . $watchNote;
            }
        }

        // ── Entry signal evaluation (always runs, regardless of signal state) ──
        $entryResult = $entryService->evaluate([
            'label'         => $label,
            'signal_action' => $signal['action'],
            'signal_conf'   => (float) ($signal['confidence'] ?? 0.0),
            'calendar_risk' => $calendarRisk,
            'ai_bias'       => $aiBias,
            'ai_confidence' => $aiConf,
            'today_count'   => $todayCount,
            'now'           => $now,
        ]);

        // ── Unified Market Snapshot + Decision Engine (additive, informational
        //    only — does not change what's persisted to signal_events below) ──
        $decisionResult = $this->buildDecision(
            $symbol, $label, $rows, $signal, $sentimentSummary, $calendarRisk, $aiBias, $aiConf
        );

        // ── No actionable signal from engine ──────────────────────────────────
        if (\in_array($signal['action'], ['WAIT', 'HOLD', 'AVOID', 'WATCH'], true)) {
            $reasonText = implode('; ', $signal['reasons']);
            $logger->log(
                'ok',
                'No actionable signal for ' . $label . ': ' . $signal['action'] . ' — ' . $reasonText,
                $symbol
            );
            return [
                'status'   => 'ok',
                'market'   => $label,
                'action'   => $signal['action'],
                'message'  => 'No actionable signal',
                'details'  => $reasonText,
                'entry'    => $entryResult,
                'decision' => $decisionResult,
            ];
        }

        // ── Calendar hard block (BUY/SELL only) ───────────────────────────────
        if ($calendarRisk !== null) {
            $blockReason = 'WAIT — ' . $calendarRisk['reason'];
            $logger->log('ok', 'Calendar block for ' . $label . ': ' . $calendarRisk['event_title'], $symbol);
            return [
                'status'   => 'ok',
                'market'   => $label,
                'action'   => 'WAIT',
                'message'  => 'No actionable signal',
                'details'  => $blockReason,
                'entry'    => $entryResult,
                'decision' => $decisionResult,
            ];
        }

        // ── Persist signal event ──────────────────────────────────────────────
        $eventId = SignalEvent::create([
            'market_symbol'      => $symbol,
            'market_label'       => $label,
            'timeframe'          => (string) env('MARKET_TIMEFRAME', '60m'),
            'session_name'       => (string) $signal['session_name'],
            'action'             => (string) $signal['action'],
            'confidence'         => (float)  $signal['confidence'],
            'entry_window_start' => $signal['entry_window_start'],
            'entry_window_end'   => $signal['entry_window_end'],
            'hold_hours_min'     => (int)    $signal['hold_hours_min'],
            'hold_hours_max'     => (int)    $signal['hold_hours_max'],
            'stop_loss_hint'     => (string) $signal['stop_loss_hint'],
            'take_profit_hint'   => (string) $signal['take_profit_hint'],
            'reasons'            => implode("\n", $signal['reasons']),
            'risk_warning'       => (string) $signal['risk_warning'],
            'status'             => 'new',
            'signal_date'        => $signalDate,
            'was_alert_sent'     => 0,
        ]);

        // ── Email alert ───────────────────────────────────────────────────────
        if ((float) $signal['confidence'] >= (float) env('MIN_CONFIDENCE_TO_ALERT', 65)) {
            $entryStateInfo = $entryResult['state'] === 'ENTRY'
                ? "\nEntry Engine State: {$entryResult['state']} — {$entryResult['entry_reason']}\n"
                : '';

            $body = "Market: {$label}" . PHP_EOL
                  . "Action: {$signal['action']}" . PHP_EOL
                  . "Confidence: {$signal['confidence']}%" . PHP_EOL
                  . "Session: {$signal['session_name']}" . PHP_EOL
                  . "Entry Window: {$signal['entry_window_start']} to {$signal['entry_window_end']}" . PHP_EOL
                  . "Hold Duration: {$signal['hold_hours_min']}-{$signal['hold_hours_max']} hours" . PHP_EOL
                  . "Stop: {$signal['stop_loss_hint']}" . PHP_EOL
                  . "Target: {$signal['take_profit_hint']}" . PHP_EOL . PHP_EOL
                  . "Reasons:" . PHP_EOL
                  . '- ' . implode(PHP_EOL . '- ', $signal['reasons']) . PHP_EOL
                  . $entryStateInfo . PHP_EOL
                  . "Risk Warning:" . PHP_EOL . $signal['risk_warning'];

            $subject = "[{$label}] {$signal['action']} signal ({$signal['confidence']}%)"
                     . ($entryResult['state'] === 'ENTRY' ? ' — ENTRY CONFIRMED' : '');

            $emailSent = $notifier->sendSignalEmail($subject, $body);
            // Best-effort, independent of the email result — a Telegram
            // failure (not configured, network blip) must never block the
            // email alert or the was_alert_sent bookkeeping, same fail-soft
            // stance as every other notification channel in this app.
            $telegram->sendToAdmin($subject . "\n\n" . $body);

            if ($emailSent) {
                SignalEvent::markAlertSent($eventId);
            }
        }

        $logger->log(
            'ok',
            'Signal created for ' . $label . ': ' . $signal['action'] . ' at ' . $signal['confidence'] . '%'
            . ' | Entry state: ' . $entryResult['state'],
            $symbol
        );

        return [
            'status'     => 'ok',
            'market'     => $label,
            'action'     => $signal['action'],
            'confidence' => $signal['confidence'],
            'message'    => 'Signal created: ' . $signal['action'],
            'entry'      => $entryResult,
            'decision'   => $decisionResult,
        ];
    }

    /**
     * Builds a MarketSnapshot and runs it through the Decision Engine, then
     * persists the result to ai_decisions. Wrapped so a failure here can
     * never break the existing scan/alert/persist flow above — mirrors the
     * non-fatal try/catch pattern already used for the AI summary call below.
     */
    private function buildDecision(
        string $symbol,
        string $label,
        array $rows,
        array $signal,
        array $sentimentSummary,
        ?array $calendarRisk,
        string $aiBias,
        float $aiConf
    ): ?array {
        try {
            $snapshot = MarketSnapshotBuilder::build(
                $symbol,
                (string) env('MARKET_TIMEFRAME', '60m'),
                $rows,
                $signal,
                $sentimentSummary,
                $calendarRisk,
                $aiBias,
                $aiConf
            );

            $decision    = (new DecisionEngine())->decide($snapshot);
            $explanation = (new \App\Explanation\ExplanationEngine())->explain($decision);

            AiDecision::create([
                'market_label' => $label,
                'timeframe'    => $snapshot->timeframe,
                'decision'     => $decision->decision,
                'confidence'   => $decision->confidence,
                'reasoning'    => implode("\n", $explanation->reasoning),
                'conflicts'    => implode("\n", $explanation->conflicts) ?: null,
                'risk_level'   => $decision->riskLevel,
                'provider'     => 'decision_engine',
                'snapshot_id'  => null,
            ]);

            return $decision->toArray() + ['explanation' => $explanation->toArray()];
        } catch (\Throwable $e) {
            error_log('[MarketScanService] Decision Engine error for ' . $label . ': ' . $e->getMessage());
            return null;
        }
    }

    private function buildSkippedEntry(string $label, string $reason): array
    {
        return [
            'state'        => 'WAIT',
            'action'       => null,
            'pair'         => $label,
            'confidence'   => 20,
            'entry_reason' => $reason,
            'short_reason' => $reason,
            'conditions'   => [
                'session' => false, 'trend' => false, 'momentum' => false,
                'news_alignment' => false, 'risk_clear' => false,
            ],
        ];
    }

    private function formatSummaryPart(string $label, array $result): string
    {
        $status = $result['status'] ?? 'ok';
        $action = $result['action'] ?? '';
        $entry  = $result['entry']  ?? [];

        if ($status === 'error')   { return $label . ': ERROR'; }
        if ($status === 'skipped') { return $label . ': SKIPPED — ' . ($result['message'] ?? ''); }

        $entryState = ($entry['state'] ?? '') !== '' ? ' [' . $entry['state'] . ']' : '';

        if (\in_array($action, ['BUY', 'SELL'], true)) {
            $conf = isset($result['confidence']) ? ' (' . $result['confidence'] . '%)' : '';
            return $label . ': ' . $action . $conf . $entryState;
        }

        if ($action !== '') {
            $details = $result['details'] ?? '';
            $snippet = $details !== '' ? ' — ' . mb_substr($details, 0, 60) : '';
            return $label . ': ' . $action . $snippet . $entryState;
        }

        return $label . ': ' . ($result['message'] ?? 'ok') . $entryState;
    }
}
