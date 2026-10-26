<?php
declare(strict_types=1);
namespace App\MarketOverview;

use App\Core\Database;
use App\Models\AiSummary;
use App\Services\EconomicCalendarService;
use App\Services\IndicatorService;
use App\Services\MarketDataService;
use App\Services\SignalEngineService;
use PDO;
use Throwable;

/**
 * Read-only signal preview for the Live Market Overview table's Signal/
 * Confidence columns on instruments outside configured_markets(). Reuses
 * the exact same App\Services\SignalEngineService (TrendFollowingStrategy)
 * the real Market Scanner uses, on the same H1/60d candle timeframe —
 * confirmed both are pure functions of candles + a sentiment score, no
 * database write, no AI provider call, no daily/weekly cap check, no alert
 * email. Calling them directly here (instead of through
 * MarketScanService::scanMarket()'s persist/alert/cap wrapper) gives a
 * genuine read-only "what would the signal be" computation.
 *
 * For the 3 officially-scanned pairs, the dashboard keeps showing the
 * authoritative entry_signals.json read (see MarketOverviewController) —
 * this class only extends the same kind of signal to instruments that
 * pipeline has never covered, so the two never visibly disagree for the
 * same pair.
 */
final class SignalPreviewService
{
    public function __construct(
        private IndicatorService $indicators = new IndicatorService(),
        private SignalEngineService $engine = new SignalEngineService(),
        private EconomicCalendarService $calendar = new EconomicCalendarService(),
    ) {
    }

    /** @return array{action:string, confidence:float}|null Null on any fetch/compute failure — caller shows "—", never breaks the request. */
    public function evaluate(string $symbol, string $label, array $sentimentSummary): ?array
    {
        try {
            $rows = $this->indicators->enrich((new MarketDataService())->fetchCandles($symbol));
            if (count($rows) < 2) {
                return null;
            }

            $signal = $this->engine->evaluate($rows, $sentimentSummary);

            // Mirror MarketScanService's calendar hard-block: an active
            // high-impact event downgrades an actionable call to WAIT,
            // same as the official scan does for configured pairs.
            $calendarRisk = $this->calendar->getActiveRiskWindow($label, now_eat());
            if ($calendarRisk !== null && \in_array($signal['action'], ['BUY', 'SELL'], true)) {
                $signal['action'] = 'WAIT';
            }

            return ['action' => (string) $signal['action'], 'confidence' => (float) $signal['confidence']];
        } catch (Throwable $e) {
            error_log("[SignalPreviewService] {$label} preview failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Reads the same polarity×impact average MarketScanService::
     * buildSentimentSummary() computes fresh each scan cycle, from already-
     * persisted news_items rows (raw_sentiment/impact_score, written at
     * ingestion time by that exact same formula) instead of re-fetching and
     * re-analyzing news on every dashboard page load.
     */
    public static function approximateSentimentSummary(int $limit = 12): array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT raw_sentiment, impact_score FROM news_items ORDER BY created_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                return ['score' => 0.0];
            }

            $scores = array_map(
                static fn(array $r): float => (float) $r['raw_sentiment'] * (float) $r['impact_score'],
                $rows
            );
            return ['score' => array_sum($scores) / count($scores)];
        } catch (Throwable $e) {
            error_log('[SignalPreviewService] approximateSentimentSummary failed: ' . $e->getMessage());
            return ['score' => 0.0];
        }
    }
}
