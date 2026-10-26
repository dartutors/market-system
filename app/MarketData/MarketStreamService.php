<?php
declare(strict_types=1);
namespace App\MarketData;

use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Models\Quote;
use Throwable;

/**
 * Phase 6 — "prepare the architecture for live updates." Honest scoping for
 * this app's actual deployment (Windows/XAMPP, Task Scheduler invoking
 * short-lived PHP-CLI processes — see cron/market_scan.php's own doc
 * comment about avoiding APCu for the same reason): there is no persistent
 * PHP process and no pub/sub or websocket-to-browser layer anywhere in this
 * codebase, so "notify scanner/dashboard/AI modules" cannot mean a real-time
 * push to an open browser tab here. It means: poll() refreshes
 * MarketDataManager's shared, provider-agnostic file cache
 * (App\MarketData\Providers\CachedMarketProvider) for every configured
 * market, so the *next* time the scanner/dashboard/any AI module calls the
 * manager — whenever that next request happens — it gets fresh data
 * instantly from cache instead of making its own live call. That is the
 * real, working notification mechanism available in this architecture.
 *
 * Two ways to run this:
 *   - poll() once — suitable for a Task Scheduler entry every N
 *     minutes/seconds, same lock-file idiom as cron/market_scan.php (see
 *     cron/market_stream.php).
 *   - watch() — loops poll() at an interval and never returns; only
 *     meaningful if an operator runs it as a genuinely long-lived process
 *     (e.g. via NSSM as a Windows service). Not invoked by any web request
 *     or by cron/market_stream.php's default (single-poll) mode.
 */
final class MarketStreamService
{
    private const LAST_QUOTE_CACHE_DIR = 'storage/cache/market_stream';

    public function __construct(private MarketDataManager $manager = new MarketDataManager())
    {
    }

    /**
     * One polling cycle across every configured market.
     *
     * @return array<int, array{symbol:string, label:string, changed:bool, quote:array, error:?string}>
     */
    public function poll(): array
    {
        $notifications = [];

        foreach (configured_markets() as $market) {
            $entry = ['symbol' => $market['symbol'], 'label' => $market['label'], 'changed' => false, 'quote' => null, 'error' => null];

            try {
                $quote = $this->manager->getQuote($market['symbol']);
                $entry['changed'] = $this->hasChanged($market['symbol'], $quote);
                $entry['quote']   = $quote->toArray();
                $this->rememberLast($market['symbol'], $quote);
            } catch (Throwable $e) {
                $entry['error'] = $e->getMessage();
                error_log("[MarketStreamService] {$market['label']} quote poll failed: " . $e->getMessage());
            }

            $notifications[] = $entry;
        }

        return $notifications;
    }

    /**
     * Loops poll() at $intervalSeconds forever. Only call this from a
     * script an operator intends to run as a long-lived process — never
     * from a web request or a Task-Scheduler-style periodic invocation
     * (that should call poll() once instead; see cron/market_stream.php).
     *
     * @param callable(array):void|null $onTick Called with poll()'s return value after every cycle.
     */
    public function watch(int $intervalSeconds, ?callable $onTick = null): never
    {
        $intervalSeconds = max(1, $intervalSeconds);
        $running = true;

        if (\function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$running): void { $running = false; };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        while ($running) {
            $result = $this->poll();
            if ($onTick !== null) {
                $onTick($result);
            }
            sleep($intervalSeconds);
        }

        exit(0);
    }

    private function hasChanged(string $symbol, Quote $quote): bool
    {
        $previous = $this->readLast($symbol);
        return $previous === null || abs($previous->mid() - $quote->mid()) > 0.0000001;
    }

    private function rememberLast(string $symbol, Quote $quote): void
    {
        $dir = base_path(self::LAST_QUOTE_CACHE_DIR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path    = $dir . DIRECTORY_SEPARATOR . $this->safeKey($symbol) . '.json';
        $payload = json_encode($quote->toArray(), JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }

    private function readLast(string $symbol): ?Quote
    {
        $path = base_path(self::LAST_QUOTE_CACHE_DIR) . DIRECTORY_SEPARATOR . $this->safeKey($symbol) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!\is_array($data) || !isset($data['symbol'], $data['bid'], $data['ask'], $data['timestamp'], $data['provider'])) {
            return null;
        }
        return new Quote(
            symbol: (string) $data['symbol'],
            bid: (float) $data['bid'],
            ask: (float) $data['ask'],
            timestamp: (int) $data['timestamp'],
            provider: (string) $data['provider'],
            spreadIsReal: (bool) ($data['spread_is_real'] ?? false),
        );
    }

    private function safeKey(string $symbol): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $symbol) ?? 'unknown';
    }
}
