<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\MarketIntelligence\PairSnapshotFetcher;
use App\MarketIntelligence\VolatilityIndexCalculator;
use App\MarketOverview\InstrumentCatalog;
use App\MarketOverview\SignalPreviewService;
use App\Models\WatchlistItem;
use Throwable;

/**
 * JSON data source for the dashboard's Section 3 "Live Market Overview"
 * table. Price/trend/volatility are always read-only (App\MarketIntelligence
 * \PairSnapshotFetcher, the same class /market-intelligence uses). Signal/
 * Confidence are read-only too, but from two different sources depending on
 * the instrument (see `signal_official` in the response):
 *  - The 3 pairs in configured_markets() show the AUTHORITATIVE signal —
 *    the real scanned, alerted, daily-capped read from entry_signals.json,
 *    identical to what the Decision Center / Recent Signals show elsewhere.
 *  - Every other instrument shows a PREVIEW — computed live via the exact
 *    same App\Services\SignalEngineService the real scanner uses (confirmed
 *    a pure function of candles + a sentiment score, no AI call, no DB
 *    write), but never persisted, alerted, or subject to the daily cap.
 * Polled periodically by client JS rather than pushed, per this app's
 * no-WebSocket-to-browser front end.
 *
 * 22 instruments x a 2-year daily candle fetch (price/trend/volatility) plus,
 * for the ~19 non-configured ones, an H1/60d candle fetch (signal preview)
 * — measured at 17-54s for the daily fetch alone the first time (Yahoo
 * Finance over the network, sequentially — this app has no queue/worker to
 * parallelize it), so the signal preview roughly doubles worst-case cold
 * time. The full computed response is cached to
 * storage/cache/market_overview.json for CACHE_TTL_SECONDS — same
 * storage/cache/*.json convention this app already uses for
 * ai_insight.json/entry_signals.json. Only the first request after the
 * cache expires pays the slow computation; everyone else gets a near-
 * instant file read.
 */
final class MarketOverviewController extends Controller
{
    private const CACHE_TTL_SECONDS = 300;

    public function quotes(): void
    {
        require_login();

        // Cold path now fetches both the Daily/2y snapshot AND (for
        // non-configured instruments) an H1/60d candle series for the
        // signal preview — roughly doubling worst-case external-fetch time
        // versus the 17-54s already measured for the snapshot alone. This
        // request-scoped bump (not a global php.ini change) gives headroom
        // under PHP's 120s default without touching every other request.
        set_time_limit(280);

        $cachePath = base_path('storage/cache/market_overview.json');
        $cached    = $this->readFreshCache($cachePath);
        $payload   = $cached ?? [
            'generated_at' => now_eat()->format('c'),
            'instruments'  => $this->computeInstruments(),
        ];

        if ($cached === null) {
            $written = @file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($written === false) {
                error_log("[MarketOverviewController] failed to write cache file: {$cachePath}");
            }
        }

        // Watchlist membership (Section 12) is applied fresh every request,
        // outside the price cache — pinning/unpinning must feel instant, not
        // wait out the 5-minute price-data cache window.
        $pinned = WatchlistItem::pinnedSymbols();
        foreach ($payload['instruments'] as &$row) {
            $row['watching'] = \in_array($row['symbol'], $pinned, true);
        }
        unset($row);

        $this->json($payload);
    }

    /** @return array<int, array<string, mixed>> */
    private function computeInstruments(): array
    {
        $configuredLabels = array_column(configured_markets(), 'label');
        $entrySignals      = $this->loadEntrySignals();

        $fetcher       = new PairSnapshotFetcher();
        $signalPreview = new SignalPreviewService();
        $sentimentSummary = SignalPreviewService::approximateSentimentSummary();
        $rows = [];

        // Deadline guard: with set_time_limit(280) and no queue/worker to
        // parallelize ~22 sequential external fetches, a run of slow-but-not-
        // dead providers could otherwise hit PHP's uncatchable max-execution
        // fatal mid-loop — losing everything computed so far, since the
        // cache write only happens after this method returns. Past this
        // deadline, skip remaining external fetches (row stays "available":
        // false, matching the existing per-instrument failure shape) so the
        // method always returns and the caller always gets something to
        // cache, instead of a hard crash with no self-healing.
        $deadline = microtime(true) + 250;

        foreach (InstrumentCatalog::all() as $instrument) {
            $row = [
                'symbol'          => $instrument['symbol'],
                'label'           => $instrument['label'],
                'category'        => $instrument['category'],
                'price'           => null,
                'change_pct'      => null,
                'trend'           => null,
                'volatility'      => null,
                'signal'          => null,
                'confidence'      => null,
                'signal_official' => \in_array($instrument['label'], $configuredLabels, true),
                'available'       => false,
            ];

            if (microtime(true) < $deadline) {
                try {
                    $snap = $fetcher->fetchOne($instrument['symbol'], $instrument['label']);
                    $row['price']      = $snap->close;
                    $row['change_pct'] = $snap->dailyReturnPct;
                    $row['trend']      = $snap->trend;
                    $row['volatility'] = VolatilityIndexCalculator::levelFor($snap->atrPercent);
                    $row['available']  = true;
                } catch (Throwable $e) {
                    error_log("[MarketOverviewController] {$instrument['label']} snapshot failed: " . $e->getMessage());
                }

                if ($row['signal_official']) {
                    // Authoritative: the real scanned, alerted, capped signal —
                    // same value the Decision Center and Recent Signals show.
                    $entry = $entrySignals[$instrument['label']] ?? null;
                    if (\is_array($entry)) {
                        $row['signal']     = strtoupper((string) ($entry['action'] ?? $entry['state'] ?? 'WAIT'));
                        $row['confidence'] = (int) ($entry['confidence'] ?? 0);
                    }
                } elseif (microtime(true) < $deadline) {
                    // Preview: same signal engine, computed read-only — never
                    // persisted, never alerted, never subject to the daily cap.
                    $preview = $signalPreview->evaluate($instrument['symbol'], $instrument['label'], $sentimentSummary);
                    if ($preview !== null) {
                        $row['signal']     = strtoupper($preview['action']);
                        $row['confidence'] = (int) round($preview['confidence']);
                    }
                }
            } else {
                error_log("[MarketOverviewController] deadline reached before processing {$instrument['label']} — returning partial results.");
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @return ?array<string, mixed> Null when missing, unreadable, unparseable, or stale. */
    private function readFreshCache(string $path): ?array
    {
        if (!file_exists($path) || (time() - (int) filemtime($path)) > self::CACHE_TTL_SECONDS) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return \is_array($data) ? $data : null;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadEntrySignals(): array
    {
        $path = base_path('storage/cache/entry_signals.json');
        if (!file_exists($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        $entries = \is_array($data) ? ($data['entries'] ?? null) : null;
        return \is_array($entries) ? $entries : [];
    }
}
