<?php
declare(strict_types=1);

/**
 * Deterministic + live regression checks for Phase 2.2A — Deriv Live Market
 * Data Integration (app/MarketData/*). Pure logic (TimeframeCatalog,
 * DerivSymbolMapper, model toArray()/round-trips, the fallback chain itself)
 * is tested with in-memory fake providers so it never depends on network
 * availability; live Deriv/Yahoo connectivity is verified separately and
 * tolerates being offline (SKIP, not FAIL) the same way prior phases'
 * live-provider checks do. Synthetic log rows this script writes (symbol
 * 'TESTXYZ=X') are deleted at the end — real provider-call logs from actual
 * application use are left untouched.
 *
 * Run with:
 *   php bin/verify-phase2-2a-marketdata.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Contracts\MarketDataProviderInterface as LegacyInterface;
use App\Core\Database;
use App\MarketData\Contracts\MarketDataProviderInterface;
use App\MarketData\Exceptions\MarketDataException;
use App\MarketData\Exceptions\ProviderUnavailableException;
use App\MarketData\Exceptions\UnsupportedTimeframeException;
use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\MarketData\Models\MarketSnapshot;
use App\MarketData\Models\Quote;
use App\MarketData\Providers\CachedMarketProvider;
use App\MarketData\Providers\DerivProvider;
use App\MarketData\Providers\DerivSymbolMapper;
use App\MarketData\Providers\YahooFinanceProvider;
use App\Models\MarketDataProviderLog;

$failures = 0;

function checkTrue(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// ── Fake providers for deterministic fallback-chain testing ────────────────
final class FakeAlwaysFailsProvider implements MarketDataProviderInterface
{
    public function __construct(private string $failureMessage = 'fake provider always fails') {}
    public function name(): string { return 'fake_failing'; }
    public function getCandles(string $symbol, string $timeframe): array { throw new ProviderUnavailableException($this->failureMessage); }
    public function getQuote(string $symbol): Quote { throw new ProviderUnavailableException($this->failureMessage); }
    public function getMarketStatus(string $symbol): array { throw new ProviderUnavailableException($this->failureMessage); }
    public function supportsTimeframe(string $timeframe): bool { return true; }
}

final class FakeAlwaysSucceedsProvider implements MarketDataProviderInterface
{
    public function name(): string { return 'fake_succeeding'; }
    public function getCandles(string $symbol, string $timeframe): array
    {
        return [new Candle($symbol, $timeframe, 1000, 1.1, 1.2, 1.0, 1.15, 100.0, $this->name())];
    }
    public function getQuote(string $symbol): Quote
    {
        return new Quote($symbol, 1.1499, 1.1501, 1000, $this->name(), true);
    }
    public function getMarketStatus(string $symbol): array
    {
        return ['symbol' => $symbol, 'is_open' => true, 'provider' => $this->name()];
    }
    public function supportsTimeframe(string $timeframe): bool { return true; }
}

// ── 1. TimeframeCatalog ──────────────────────────────────────────────────────
checkTrue('TimeframeCatalog::labels() has all 9 timeframes', count(TimeframeCatalog::labels()) === 9, (string) count(TimeframeCatalog::labels()));
checkTrue('TimeframeCatalog::labels() includes M1 (new for Phase 2.2A)', in_array('M1', TimeframeCatalog::labels(), true));
checkTrue('TimeframeCatalog: Monthly has no Deriv granularity (Deriv has no native monthly candles)', TimeframeCatalog::derivGranularitySeconds('Monthly') === null);
checkTrue('TimeframeCatalog: Weekly has no Deriv granularity', TimeframeCatalog::derivGranularitySeconds('Weekly') === null);
checkTrue('TimeframeCatalog: Daily has Deriv granularity 86400', TimeframeCatalog::derivGranularitySeconds('Daily') === 86400);
checkTrue('TimeframeCatalog: H1 has Deriv granularity 3600', TimeframeCatalog::derivGranularitySeconds('H1') === 3600);
checkTrue('TimeframeCatalog::has() rejects an unknown label', !TimeframeCatalog::has('Bogus'));
$threw = false;
try { TimeframeCatalog::get('Bogus'); } catch (\InvalidArgumentException) { $threw = true; }
checkTrue('TimeframeCatalog::get() throws for an unknown label', $threw);

// ── 2. DerivSymbolMapper ─────────────────────────────────────────────────────
checkTrue('DerivSymbolMapper::toDerivSymbol() converts EURUSD=X correctly', DerivSymbolMapper::toDerivSymbol('EURUSD=X') === 'frxEURUSD');
checkTrue('DerivSymbolMapper::toDerivSymbol() converts USDJPY=X correctly', DerivSymbolMapper::toDerivSymbol('USDJPY=X') === 'frxUSDJPY');
checkTrue('DerivSymbolMapper::fromDerivSymbol() round-trips', DerivSymbolMapper::fromDerivSymbol(DerivSymbolMapper::toDerivSymbol('EURUSD=X')) === 'EURUSD=X');

// ── 3. Models: Candle / Quote / MarketSnapshot ──────────────────────────────
$candle = new Candle('EURUSD=X', 'H1', 1700000000, 1.10, 1.12, 1.09, 1.11, 500.0, 'deriv');
checkTrue('Candle::toLegacyRow() has the exact old raw-array shape', $candle->toLegacyRow() === ['timestamp' => 1700000000, 'open' => 1.10, 'high' => 1.12, 'low' => 1.09, 'close' => 1.11, 'volume' => 500.0]);

$quote = new Quote('EURUSD=X', 1.1099, 1.1101, 1700000000, 'deriv', true);
checkTrue('Quote::mid() averages bid/ask', abs($quote->mid() - 1.1100) < 0.00001);

$snapshotFromBoth = MarketSnapshot::fromCandleAndQuote($candle, $quote);
checkTrue('MarketSnapshot::fromCandleAndQuote() uses the real quote bid/ask when given', abs($snapshotFromBoth->bid - 1.1099) < 0.00001);

$snapshotCandleOnly = MarketSnapshot::fromCandleAndQuote($candle, null);
checkTrue('MarketSnapshot::fromCandleAndQuote() falls back to candle close for bid/ask with no quote', $snapshotCandleOnly->bid === $candle->close && $snapshotCandleOnly->ask === $candle->close);

// ── 4. CachedMarketProvider: stale-serve-on-failure (the new capability) ───
$cacheDir = sys_get_temp_dir() . '/mi_verify_cache_' . uniqid();
$flaky = new class implements MarketDataProviderInterface {
    public int $calls = 0;
    public function name(): string { return 'flaky'; }
    public function getCandles(string $symbol, string $timeframe): array
    {
        $this->calls++;
        if ($this->calls === 1) {
            return [new Candle($symbol, $timeframe, 1000, 1.0, 1.0, 1.0, 1.0, 0.0, $this->name())];
        }
        throw new ProviderUnavailableException('flaky provider down on call ' . $this->calls);
    }
    public function getQuote(string $symbol): Quote { throw new ProviderUnavailableException('n/a'); }
    public function getMarketStatus(string $symbol): array { return ['symbol' => $symbol, 'is_open' => true, 'provider' => $this->name()]; }
    public function supportsTimeframe(string $timeframe): bool { return true; }
};
$cached = new CachedMarketProvider($flaky, $cacheDir);

$first = $cached->getCandles('FAKE=X', 'H1');
checkTrue('CachedMarketProvider: first call reaches the inner provider and succeeds', count($first) === 1 && !$cached->wasLastCallFromCache());

// Force the cache to look expired so the next call re-hits the (now-failing) inner provider.
$cacheFile = $cacheDir . '/candles_FAKE_X_H1.json';
checkTrue('CachedMarketProvider: cache file was actually written', is_file($cacheFile));
$raw = json_decode((string) file_get_contents($cacheFile), true);
$raw['fetched_at'] = 0; // force expiry
file_put_contents($cacheFile, json_encode($raw));

$second = $cached->getCandles('FAKE=X', 'H1');
checkTrue('CachedMarketProvider: serves stale cache when the inner provider fails after expiry', count($second) === 1 && $cached->wasLastCallFromCache());
checkTrue('CachedMarketProvider: stale-served candle is retagged provider "cache"', $second[0]->provider === 'cache');

// Cleanup temp cache dir.
array_map('unlink', glob($cacheDir . '/*') ?: []);
@rmdir($cacheDir);

// ── 5. MarketDataManager: fallback chain with fake providers (deterministic) ─
// Dotenv::createImmutable() populates BOTH $_ENV and $_SERVER (see the
// AI_VISION/TRADE_COACH flag-default gotcha documented in earlier verify
// scripts) — save the real configured values now and restore both, on both
// superglobals, once every test below is done, so section 9's live checks
// see this installation's actual DERIV_APP_ID rather than a leftover fake one.
$originalDerivAppIdEnv       = $_ENV['DERIV_APP_ID']                ?? null;
$originalDerivAppIdServer    = $_SERVER['DERIV_APP_ID']             ?? null;
$originalPrimaryProviderEnv  = $_ENV['MARKET_DATA_PRIMARY_PROVIDER']    ?? null;

$_ENV['MARKET_DATA_PRIMARY_PROVIDER'] = 'deriv';
$_ENV['DERIV_APP_ID'] = 'fake_app_id_for_test';
$_SERVER['DERIV_APP_ID'] = 'fake_app_id_for_test';

// DerivProvider/YahooFinanceProvider are `final class` — fakes implement the
// interface directly rather than extending them.
$fakeFailingDeriv = new class implements MarketDataProviderInterface {
    public function name(): string { return 'deriv'; }
    public function getCandles(string $symbol, string $timeframe): array { throw new ProviderUnavailableException('simulated Deriv outage'); }
    public function getQuote(string $symbol): Quote { throw new ProviderUnavailableException('simulated Deriv outage'); }
    public function getMarketStatus(string $symbol): array { throw new ProviderUnavailableException('simulated Deriv outage'); }
    public function supportsTimeframe(string $timeframe): bool { return true; }
};
$fakeSucceedingYahoo = new class implements MarketDataProviderInterface {
    public function name(): string { return 'yahoo_finance'; }
    public function getCandles(string $symbol, string $timeframe): array
    {
        return [new Candle($symbol, $timeframe, 2000, 1.2, 1.2, 1.2, 1.2, 0.0, 'yahoo_finance')];
    }
    public function getQuote(string $symbol): Quote { return new Quote($symbol, 1.2, 1.2, 2000, 'yahoo_finance', false); }
    public function getMarketStatus(string $symbol): array { return ['symbol' => $symbol, 'is_open' => true, 'provider' => 'yahoo_finance']; }
    public function supportsTimeframe(string $timeframe): bool { return true; }
};

$managerWithFailingDeriv = new MarketDataManager(deriv: $fakeFailingDeriv, yahoo: $fakeSucceedingYahoo);

$fallbackResult = $managerWithFailingDeriv->getCandles('TESTXYZ=X', 'H1');
checkTrue('MarketDataManager: falls through to Yahoo when Deriv throws', count($fallbackResult) === 1 && $fallbackResult[0]->provider === 'yahoo_finance');

// latest(50), not latest(5) — a full regression sweep may run other scripts'
// live scanner/pattern-recognition tests immediately before this one, which
// write their own real log rows and can otherwise push this synthetic row
// out of a too-narrow window (observed during Phase 2.9's sweep).
$logs = MarketDataProviderLog::latest(50);
$fallbackLogged = false;
foreach ($logs as $row) {
    if ($row['symbol'] === 'TESTXYZ=X' && (int) $row['was_fallback'] === 1 && $row['provider_used'] === 'yahoo_finance') {
        $fallbackLogged = true;
        break;
    }
}
checkTrue('MarketDataManager: logs the fallback event with was_fallback=1', $fallbackLogged);

// Total failure: both providers throw -> ProviderUnavailableException propagates, never a fatal error.
$fakeFailingYahoo = new class implements MarketDataProviderInterface {
    public function name(): string { return 'yahoo_finance'; }
    public function getCandles(string $symbol, string $timeframe): array { throw new ProviderUnavailableException('simulated Yahoo outage too'); }
    public function getQuote(string $symbol): Quote { throw new ProviderUnavailableException('simulated Yahoo outage too'); }
    public function getMarketStatus(string $symbol): array { throw new ProviderUnavailableException('simulated Yahoo outage too'); }
    public function supportsTimeframe(string $timeframe): bool { return true; }
};
$managerTotalFailure = new MarketDataManager(deriv: $fakeFailingDeriv, yahoo: $fakeFailingYahoo);
$threw = false;
try {
    // A distinct fake symbol from the fallback test above — both tests share
    // the real storage/cache/market_data_v2 directory (MarketDataManager
    // doesn't expose a custom cache dir), so reusing the same symbol/
    // timeframe key here would silently serve the earlier test's cached
    // success instead of exercising a genuine double-failure.
    $managerTotalFailure->getCandles('TESTFAIL=X', 'H1');
} catch (ProviderUnavailableException $e) {
    $threw = true;
}
checkTrue('MarketDataManager: total provider failure raises ProviderUnavailableException (a RuntimeException), not a fatal error', $threw);

// Restore this installation's real DERIV_APP_ID/MARKET_DATA_PRIMARY_PROVIDER
// (both $_ENV and $_SERVER) before any later check reads them.
if ($originalDerivAppIdEnv !== null)      { $_ENV['DERIV_APP_ID'] = $originalDerivAppIdEnv; }         else { unset($_ENV['DERIV_APP_ID']); }
if ($originalDerivAppIdServer !== null)   { $_SERVER['DERIV_APP_ID'] = $originalDerivAppIdServer; }   else { unset($_SERVER['DERIV_APP_ID']); }
if ($originalPrimaryProviderEnv !== null) { $_ENV['MARKET_DATA_PRIMARY_PROVIDER'] = $originalPrimaryProviderEnv; } else { unset($_ENV['MARKET_DATA_PRIMARY_PROVIDER']); }

// ── 6. MarketDataManager implements BOTH interfaces (the compatibility guarantee) ─
checkTrue('MarketDataManager implements the new App\MarketData\Contracts\MarketDataProviderInterface', $managerWithFailingDeriv instanceof MarketDataProviderInterface);
checkTrue('MarketDataManager implements the pre-existing App\Contracts\MarketDataProviderInterface (drop-in compatibility)', $managerWithFailingDeriv instanceof LegacyInterface);

// ── 7. Legacy fetchCandles() catalog-label matching ─────────────────────────
// $managerWithFailingDeriv's injected `deriv`/`yahoo` fakes only affect the
// NEW getCandles()/getQuote() API — the legacy fetchCandles() compatibility
// path always falls through to the real, unmodified
// App\Services\MarketData\CachedMarketDataProvider(YahooFinanceProvider)
// stack for its final tier (that stack isn't dependency-injected, by
// design — it's the exact pre-existing code every consumer already used),
// so this needs a real symbol rather than a fake one; tolerated as a
// best-effort live check like section 9 below.
try {
    $legacyRows = $managerWithFailingDeriv->fetchCandles('EURUSD=X', '60m', '60d'); // matches H1 exactly
    checkTrue('MarketDataManager::fetchCandles() returns the old raw-array shape', array_keys($legacyRows[0]) === ['timestamp', 'open', 'high', 'low', 'close', 'volume']);
} catch (\Throwable $e) {
    echo "SKIP  MarketDataManager::fetchCandles() legacy-shape check — network unavailable: " . $e->getMessage() . "\n";
}

// ── 8. MarketDataProviderLog model ──────────────────────────────────────────
$logId = MarketDataProviderLog::create([
    'operation' => 'candles', 'symbol' => 'TESTXYZ=X', 'timeframe' => 'H1',
    'provider_used' => 'fake_succeeding', 'primary_provider' => 'deriv',
    'was_fallback' => 0, 'was_cache' => 0, 'success' => 1, 'latency_ms' => 42, 'error_message' => null,
]);
checkTrue('MarketDataProviderLog::create() returns a positive insert id', $logId > 0);
$statsByProvider = MarketDataProviderLog::statsByProvider();
checkTrue('MarketDataProviderLog::statsByProvider() returns an array', is_array($statsByProvider));

// ── Cleanup: remove every synthetic log row + shared-cache file this script created ──
Database::connection()->prepare("DELETE FROM market_data_provider_logs WHERE symbol IN ('TESTXYZ=X', 'TESTFAIL=X')")->execute();
$remaining = Database::connection()->query("SELECT COUNT(*) FROM market_data_provider_logs WHERE symbol IN ('TESTXYZ=X', 'TESTFAIL=X')")->fetchColumn();
checkTrue('Cleanup: all synthetic log rows removed', (int) $remaining === 0);

foreach (['TESTXYZ_X_H1', 'TESTFAIL_X_H1'] as $sharedCacheKey) {
    $sharedCacheFile = base_path('storage/cache/market_data_v2/candles_' . $sharedCacheKey . '.json');
    if (is_file($sharedCacheFile)) {
        unlink($sharedCacheFile);
    }
}

// ── 9. Live connectivity (best-effort — SKIP, not FAIL, if offline) ────────
try {
    $liveDeriv = new DerivProvider();
    $status = $liveDeriv->getMarketStatus('EURUSD=X');
    checkTrue('Live: DerivProvider::getMarketStatus() reaches the real Deriv API', isset($status['is_open']));

    $liveQuote = $liveDeriv->getQuote('EURUSD=X');
    checkTrue('Live: DerivProvider::getQuote() returns a real bid/ask spread', $liveQuote->spreadIsReal && $liveQuote->bid > 0 && $liveQuote->ask > $liveQuote->bid);

    $liveCandles = $liveDeriv->getCandles('EURUSD=X', 'H1');
    checkTrue('Live: DerivProvider::getCandles() returns real H1 candles', count($liveCandles) > 50);
} catch (\Throwable $e) {
    echo "SKIP  Live Deriv connectivity checks — network/API unavailable: " . $e->getMessage() . "\n";
}

try {
    $liveManager = new MarketDataManager();
    $liveSnapshot = $liveManager->getSnapshot('EURUSD=X', 'Daily');
    checkTrue('Live: MarketDataManager::getSnapshot() produces a coherent snapshot', $liveSnapshot->close > 0 && $liveSnapshot->symbol === 'EURUSD=X');
} catch (\Throwable $e) {
    echo "SKIP  Live MarketDataManager snapshot check — network/API unavailable: " . $e->getMessage() . "\n";
}

// Monthly must never attempt Deriv (UnsupportedTimeframeException is Deriv's own internal signal — the
// manager/catalog routes it to Yahoo automatically; this asserts the DerivProvider-level contract directly).
$threw = false;
try {
    (new DerivProvider())->getCandles('EURUSD=X', 'Monthly');
} catch (UnsupportedTimeframeException $e) {
    $threw = true;
}
checkTrue('DerivProvider::getCandles() throws UnsupportedTimeframeException for Monthly (never attempts a network call)', $threw);

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
