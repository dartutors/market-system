<?php
declare(strict_types=1);
namespace App\MarketData\Providers;

use App\MarketData\Contracts\MarketDataProviderInterface;
use App\MarketData\Exceptions\ProviderUnavailableException;
use App\MarketData\Exceptions\UnsupportedTimeframeException;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\MarketData\Models\Quote;
use Throwable;
use WebSocket\Client as WebSocketClient;

/**
 * Deriv WebSocket API v3 provider — market data only (ticks_history,
 * ticks, active_symbols). Never sends buy/sell/authorize-for-trading
 * requests; this system does not execute trades (see the module's own
 * doc comment on that boundary).
 *
 * Deployment reality (Windows/XAMPP + Task Scheduler-invoked short-lived
 * PHP-CLI cron, no persistent PHP process, no ext-sockets/Swoole/ReactPHP):
 * a single always-open WebSocket connection held across requests is not
 * feasible here without new operational infrastructure (e.g. a Windows
 * service). This class instead opens a fresh connection per logical
 * request (via textalk/websocket's synchronous Client), sends one JSON
 * request, reads one JSON response, and closes — "reconnect" in this
 * context means "cleanly open a new connection on the next attempt/call,"
 * not "resume one long-lived socket." True always-on push streaming is
 * addressed at the App\MarketData\MarketStreamService layer (Phase 6),
 * which is explicitly a poll-and-cache design for the same deployment
 * reason, not a websocket-to-browser push system.
 *
 * All configuration is env-driven; DERIV_APP_ID has no code-level default
 * (an empty value means "Deriv is not configured," which
 * supportsTimeframe()/every method here treats as an immediate,
 * network-free failure so App\MarketData\Manager\MarketDataManager falls
 * through to Yahoo Finance instantly rather than waiting on a connection
 * that was never going to succeed).
 */
final class DerivProvider implements MarketDataProviderInterface
{
    public function name(): string
    {
        return 'deriv';
    }

    public function getCandles(string $symbol, string $timeframe): array
    {
        if (!$this->supportsTimeframe($timeframe)) {
            throw new UnsupportedTimeframeException("Deriv has no native {$timeframe} candle granularity — route this timeframe to Yahoo Finance instead.");
        }

        $derivSymbol = DerivSymbolMapper::toDerivSymbol($symbol);
        if ($derivSymbol === null) {
            throw new ProviderUnavailableException("Deriv: {$symbol} has no known Deriv symbol mapping — not attempting a doomed request.");
        }
        $granularity = TimeframeCatalog::derivGranularitySeconds($timeframe);

        $response = $this->request([
            'ticks_history'     => $derivSymbol,
            'adjust_start_time' => 1,
            'count'             => 500,
            'end'               => 'latest',
            'granularity'       => $granularity,
            'style'             => 'candles',
        ]);

        $rows = $response['candles'] ?? null;
        if (!\is_array($rows) || empty($rows)) {
            throw new ProviderUnavailableException("Deriv: no candle data returned for {$symbol} ({$timeframe}).");
        }

        return array_map(
            fn(array $row): Candle => new Candle(
                symbol: $symbol,
                timeframe: $timeframe,
                timestamp: (int) $row['epoch'],
                open: (float) $row['open'],
                high: (float) $row['high'],
                low: (float) $row['low'],
                close: (float) $row['close'],
                // Deriv's forex candles carry no traded-volume figure (it's a
                // synthetic/CFD price feed, not an exchange tape) — 0.0 is
                // honest, not a parsing gap.
                volume: 0.0,
                provider: $this->name(),
            ),
            $rows
        );
    }

    public function getQuote(string $symbol): Quote
    {
        $derivSymbol = DerivSymbolMapper::toDerivSymbol($symbol);
        if ($derivSymbol === null) {
            throw new ProviderUnavailableException("Deriv: {$symbol} has no known Deriv symbol mapping — not attempting a doomed request.");
        }
        $response = $this->request(['ticks' => $derivSymbol]);
        $tick        = $response['tick'] ?? null;

        if (!\is_array($tick) || !isset($tick['bid'], $tick['ask'], $tick['epoch'])) {
            throw new ProviderUnavailableException("Deriv: no tick data returned for {$symbol}.");
        }

        return new Quote(
            symbol: $symbol,
            bid: (float) $tick['bid'],
            ask: (float) $tick['ask'],
            timestamp: (int) $tick['epoch'],
            provider: $this->name(),
            spreadIsReal: true,
        );
    }

    public function getMarketStatus(string $symbol): array
    {
        $derivSymbol = DerivSymbolMapper::toDerivSymbol($symbol);
        if ($derivSymbol === null) {
            throw new ProviderUnavailableException("Deriv: {$symbol} has no known Deriv symbol mapping — not attempting a doomed request.");
        }
        $response = $this->request(['active_symbols' => 'brief']);
        $symbols     = $response['active_symbols'] ?? [];

        foreach ((\is_array($symbols) ? $symbols : []) as $entry) {
            if (($entry['symbol'] ?? null) === $derivSymbol) {
                return [
                    'symbol'   => $symbol,
                    'is_open'  => (bool) ($entry['exchange_is_open'] ?? false),
                    'provider' => $this->name(),
                ];
            }
        }

        throw new ProviderUnavailableException("Deriv: symbol {$derivSymbol} not found in active_symbols.");
    }

    public function supportsTimeframe(string $timeframe): bool
    {
        return TimeframeCatalog::has($timeframe) && TimeframeCatalog::derivGranularitySeconds($timeframe) !== null;
    }

    /**
     * Retry loop mirrors App\AI\AIManager::generateWith()'s existing house
     * pattern exactly: env-configured extra-attempt count (default 0 — one
     * total attempt), immediate retry, no backoff/jitter — this codebase has
     * no circuit-breaker precedent anywhere to extend instead.
     */
    private function request(array $payload): array
    {
        $appId = trim((string) env('DERIV_APP_ID', ''));
        if ($appId === '') {
            throw new ProviderUnavailableException('Deriv: DERIV_APP_ID is not configured.');
        }

        $attempts  = max(1, (int) env('DERIV_RETRY_ATTEMPTS', 0) + 1);
        $lastError = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $this->sendAndReceive($appId, $payload);
            } catch (Throwable $e) {
                $lastError = $e;
                error_log("[DerivProvider] attempt " . ($i + 1) . "/{$attempts} failed: " . $e->getMessage());
            }
        }

        throw new ProviderUnavailableException(
            'Deriv: ' . ($lastError !== null ? $lastError->getMessage() : 'unknown error'),
            0,
            $lastError
        );
    }

    private function sendAndReceive(string $appId, array $payload): array
    {
        $endpoint = rtrim((string) env('DERIV_WS_ENDPOINT', 'wss://ws.derivws.com/websockets/v3'), '/');
        $uri      = $endpoint . '?app_id=' . urlencode($appId);
        $timeout  = max(1, (int) env('DERIV_WS_TIMEOUT_SECONDS', 10));

        $client = new WebSocketClient($uri, ['timeout' => $timeout]);

        try {
            $token = trim((string) env('DERIV_API_TOKEN', ''));
            if ($token !== '') {
                // Optional — market data (ticks_history/ticks/active_symbols)
                // works fully unauthenticated; authorizing only widens this
                // connection's rate-limit tier, per Deriv's own API docs.
                $client->text(json_encode(['authorize' => $token], JSON_UNESCAPED_SLASHES));
                $client->receive();
            }

            $client->text(json_encode($payload, JSON_UNESCAPED_SLASHES));
            $raw = $client->receive();
        } finally {
            try {
                $client->close();
            } catch (Throwable) {
                // Best-effort — the socket may already be dead; nothing more to do.
            }
        }

        $data = json_decode((string) $raw, true);
        if (!\is_array($data)) {
            throw new ProviderUnavailableException('Deriv: malformed (non-JSON) response.');
        }

        if (isset($data['error'])) {
            $code    = (string) ($data['error']['code']    ?? 'UnknownError');
            $message = (string) ($data['error']['message'] ?? 'Unknown Deriv API error.');
            error_log("[DerivProvider] API error {$code}: {$message}");
            throw new ProviderUnavailableException("Deriv API error [{$code}]: {$message}");
        }

        return $data;
    }
}
