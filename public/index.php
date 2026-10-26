<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Controllers\AuthController;
use App\Controllers\BacktestController;
use App\Controllers\CalendarController;
use App\Controllers\DashboardController;
use App\Controllers\AdminToolsController;
use App\Controllers\SignalController;
use App\Controllers\VisionController;
use App\Controllers\MultiTimeframeController;
use App\Controllers\TradeCoachController;
use App\Controllers\RiskManagementController;
use App\Controllers\TradeJournalController;
use App\Controllers\MarketIntelligenceController;
use App\Controllers\LearningController;
use App\Controllers\SmartMoneyController;
use App\Controllers\AdvancedBacktestController;
use App\Controllers\MarketOverviewController;
use App\Controllers\NewsController;
use App\Controllers\WatchlistController;
use App\Controllers\ReportController;
use App\Controllers\BrandingController;
use App\Controllers\CurrencyController;
use App\Controllers\TelegramWebhookController;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$basePath = parse_url((string) env('APP_URL', ''), PHP_URL_PATH) ?: '';
if ($basePath !== '' && str_starts_with($uri, $basePath)) {
    $uri = substr($uri, strlen($basePath));
}
$uri = '/' . trim($uri, '/');

// ── Auth ──────────────────────────────────────────────────────────────────────
if ($method === 'GET'  && ($uri === '/' || $uri === '/login')) { (new AuthController())->loginForm(); exit; }
if ($method === 'POST' && $uri === '/login')                   { (new AuthController())->login();     exit; }
if ($method === 'GET'  && $uri === '/logout')                  { (new AuthController())->logout();    exit; }
if ($method === 'GET'  && $uri === '/forgot-password')          { (new AuthController())->forgotPasswordForm(); exit; }
if ($method === 'POST' && $uri === '/forgot-password')          { (new AuthController())->forgotPassword();     exit; }
if ($method === 'GET'  && $uri === '/reset-password')           { (new AuthController())->resetPasswordForm();  exit; }
if ($method === 'POST' && $uri === '/reset-password')           { (new AuthController())->resetPassword();      exit; }

// ── Dashboard ─────────────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/dashboard')               { (new DashboardController())->index();    exit; }
if ($method === 'POST' && $uri === '/admin/run-scan')          { (new AdminToolsController())->runScan(); exit; }
if ($method === 'POST' && $uri === '/admin/refresh-consensus') { (new AdminToolsController())->refreshConsensus(); exit; }

// ── Live Market Overview (read-only price/trend/volatility poll) ─────────────
if ($method === 'GET'  && $uri === '/api/market-overview/quotes') { (new MarketOverviewController())->quotes(); exit; }

// ── Currency detail (heatmap tile click-through modal) ────────────────────────
if ($method === 'GET'  && $uri === '/api/currency-detail') { (new CurrencyController())->detail(); exit; }

// ── News: click-to-explain (AI impact explanation, cached per article) ───────
if ($method === 'POST' && $uri === '/news/explain') { (new NewsController())->explain(); exit; }

// ── Watchlist: pin/unpin toggle ───────────────────────────────────────────────
if ($method === 'POST' && $uri === '/watchlist/toggle') { (new WatchlistController())->toggle(); exit; }

// ── Telegram Bot: inbound webhook (no session — secret-token authenticated) ──
if ($method === 'POST' && $uri === '/webhooks/telegram') { (new TelegramWebhookController())->receive(); exit; }

// ── Reports Center ─────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/reports') { (new ReportController())->index(); exit; }
if ($method === 'GET' && str_starts_with($uri, '/reports/')) {
    $slug = substr($uri, strlen('/reports/'));
    (new ReportController())->show($slug);
    exit;
}

// ── Branding ───────────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/branding')                { (new BrandingController())->index();        exit; }
if ($method === 'POST' && $uri === '/branding/upload-logo')    { (new BrandingController())->uploadLogo();    exit; }
if ($method === 'POST' && $uri === '/branding/upload-favicon') { (new BrandingController())->uploadFavicon(); exit; }

// PWA manifest — icons array only references sizes that actually exist
// (no favicon uploaded yet = an empty icons array, never a link to a 404).
if ($method === 'GET' && $uri === '/manifest.json') {
    header('Content-Type: application/manifest+json');
    $icons = [];
    if (\App\Branding\BrandingSettings::hasFavicon()) {
        foreach ([192, 512] as $size) {
            $sizeToUse = $size === 192 ? 128 : 512; // nearest generated size to the PWA-conventional 192
            $icons[] = [
                'src'   => \App\Branding\BrandingSettings::faviconUrl($sizeToUse),
                'sizes' => "{$sizeToUse}x{$sizeToUse}",
                'type'  => 'image/png',
            ];
        }
    }
    echo json_encode([
        'name'             => 'Market Signal System',
        'short_name'       => 'MSS',
        'start_url'        => url('dashboard'),
        'display'          => 'standalone',
        'background_color' => '#070c18',
        'theme_color'      => '#070c18',
        'icons'            => $icons,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Signal outcome + journal ──────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/signal/outcome')          { (new SignalController())->updateOutcome(); exit; }
if ($method === 'POST' && $uri === '/signal/journal')          { (new SignalController())->saveJournal();   exit; }

// ── Backtest ──────────────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/backtest')                { (new BacktestController())->index(); exit; }
if ($method === 'POST' && $uri === '/backtest/run')            { (new BacktestController())->run();   exit; }

// ── Advanced Backtesting (strategy comparison / walk-forward / Monte Carlo) ──
if ($method === 'GET'  && $uri === '/backtest/advanced')       { (new AdvancedBacktestController())->index(); exit; }
if ($method === 'POST' && $uri === '/backtest/advanced/run')   { (new AdvancedBacktestController())->run();   exit; }

// ── Economic Calendar ─────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/calendar')                { (new CalendarController())->index();   exit; }
if ($method === 'POST' && $uri === '/calendar/store')          { (new CalendarController())->store();   exit; }
if ($method === 'POST' && $uri === '/calendar/refresh')        { (new CalendarController())->refresh(); exit; }
if ($method === 'POST' && $uri === '/calendar/delete')         { (new CalendarController())->destroy(); exit; }

// ── AI Vision ─────────────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/vision')                  { (new VisionController())->index();   exit; }
if ($method === 'POST' && $uri === '/vision/analyze')          { (new VisionController())->analyze(); exit; }
if ($method === 'GET'  && $uri === '/vision/image')             { (new VisionController())->image();   exit; }

// ── Multi-Timeframe ───────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/multi-timeframe')          { (new MultiTimeframeController())->index();   exit; }
if ($method === 'POST' && $uri === '/multi-timeframe/analyze')  { (new MultiTimeframeController())->analyze(); exit; }

// ── AI Trade Coach ────────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/trade-coach')              { (new TradeCoachController())->index();  exit; }
if ($method === 'POST' && $uri === '/trade-coach/explain')      { (new TradeCoachController())->explain(); exit; }

// ── Risk Management ───────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/risk-management')          { (new RiskManagementController())->index();  exit; }
if ($method === 'POST' && $uri === '/risk-management/assess')   { (new RiskManagementController())->assess(); exit; }

// ── Trade Journal & Performance Analytics ─────────────────────────────────────
if ($method === 'GET'  && $uri === '/trade-journal')                 { (new TradeJournalController())->index();          exit; }
if ($method === 'POST' && $uri === '/trade-journal/store')           { (new TradeJournalController())->store();          exit; }
if ($method === 'POST' && $uri === '/trade-journal/update')          { (new TradeJournalController())->update();         exit; }
if ($method === 'POST' && $uri === '/trade-journal/coaching-report') { (new TradeJournalController())->coachingReport(); exit; }
if ($method === 'GET'  && $uri === '/trade-journal/archive')         { (new TradeJournalController())->archive();        exit; }

// ── Market Intelligence ────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/market-intelligence') { (new MarketIntelligenceController())->index(); exit; }

// ── AI Learning Engine ─────────────────────────────────────────────────────────
if ($method === 'GET'  && $uri === '/learning')                       { (new LearningController())->index();              exit; }
if ($method === 'POST' && $uri === '/learning/rebuild-calibration')   { (new LearningController())->rebuildCalibration();  exit; }
if ($method === 'POST' && $uri === '/learning/rebuild-patterns')      { (new LearningController())->rebuildPatterns();     exit; }
if ($method === 'GET'  && $uri === '/learning/accuracy')              { (new LearningController())->accuracyDashboard();   exit; }
if ($method === 'GET'  && $uri === '/learning/replay')                { (new LearningController())->replayIndex();         exit; }
if ($method === 'GET'  && $uri === '/learning/replay-detail')         { (new LearningController())->replay();              exit; }
if ($method === 'GET'  && $uri === '/learning/weight-proposals')      { (new LearningController())->weightProposals();     exit; }
if ($method === 'POST' && $uri === '/learning/weight-proposals/run')  { (new LearningController())->runWeightOptimizer();  exit; }
if ($method === 'POST' && $uri === '/learning/weight-proposals/decide') { (new LearningController())->decideWeightProposal(); exit; }

// ── Institutional Analysis (Smart Money Concepts) ───────────────────────────────
if ($method === 'GET'  && $uri === '/smart-money')          { (new SmartMoneyController())->index();   exit; }
if ($method === 'POST' && $uri === '/smart-money/analyze')  { (new SmartMoneyController())->analyze(); exit; }

// ── Optional web-cron endpoints (disabled by default) ────────────────────────
// Enable by setting ENABLE_WEB_CRON=true and CRON_WEB_TOKEN=<long_random_string>
// in config/.env.  CLI cron is always preferred over web cron.
if ($method === 'GET' && str_starts_with($uri, '/cron/')) {
    $webCronEnabled = (bool) env('ENABLE_WEB_CRON', false);
    $cronToken      = trim((string) env('CRON_WEB_TOKEN', ''));

    if (!$webCronEnabled || $cronToken === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Web cron is disabled.']);
        exit;
    }

    $submittedToken = trim((string) ($_GET['token'] ?? ''));
    if ($submittedToken === '' || !hash_equals($cronToken, $submittedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid cron token.']);
        exit;
    }

    header('Content-Type: application/json');

    if ($uri === '/cron/market-scan') {
        // Run market scan via web — same logic as CLI cron
        try {
            $result = (new \App\Services\MarketScanService())->run();
        } catch (\Throwable $e) {
            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }
        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    if ($uri === '/cron/calendar-refresh') {
        // Run calendar refresh via web — same logic as CLI cron
        try {
            $result = (new \App\Services\CalendarRefreshService())->refresh(30);
        } catch (\Throwable $e) {
            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }
        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Unknown cron endpoint.']);
    exit;
}

// ── 404 ───────────────────────────────────────────────────────────────────────
http_response_code(404);
echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>404</title>
<style>body{margin:0;font-family:sans-serif;background:#070c18;color:#e2e8f0;display:grid;place-items:center;min-height:100vh;text-align:center}h1{font-size:3rem}p{color:#5a7394}a{color:#3b82f6}</style>
</head><body><div><h1>404</h1><p>Page not found.</p><a href="' . e(url('login')) . '">Back to login</a></div></body></html>';
