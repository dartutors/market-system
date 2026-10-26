<?php
declare(strict_types=1);

use Dotenv\Dotenv;

require_once base_path('vendor/autoload.php');

$dotenv = Dotenv::createImmutable(config_path());
$dotenv->safeLoad();

date_default_timezone_set((string) env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));

// ── Error reporting ───────────────────────────────────────────────────────────
$logDir = base_path('storage/logs');
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

error_reporting(E_ALL);
ini_set('log_errors',  '1');
ini_set('error_log',   $logDir . DIRECTORY_SEPARATOR . 'php_errors.log');
ini_set('display_errors', (bool) env('APP_DEBUG', false) ? '1' : '0');

// ── Global fallback for anything a controller's own try/catch didn't catch ─────
// Every controller action in this app already wraps its own risky work
// (AI calls, external fetches, DB writes) in a try/catch that flashes a
// friendly message and redirects — this is the safety net underneath all of
// that, not a replacement for it. Without this, an exception that slipped
// past every local catch would hit PHP's default handler, which — with
// display_errors correctly off in production — prints nothing at all: a
// blank white page with no indication anything went wrong. Only fires on
// requests that reached this app at all (CLI cron scripts get PHP's normal
// CLI error output instead, which is what a terminal/scheduled-task log
// actually wants to see).
set_exception_handler(static function (Throwable $e) use ($logDir): void {
    error_log('[uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
    }
    $debug = (bool) env('APP_DEBUG', false);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>500 — Something went wrong</title>'
        . '<style>body{margin:0;font-family:sans-serif;background:#070c18;color:#e2e8f0;display:grid;place-items:center;min-height:100vh;text-align:center;padding:24px}'
        . 'h1{font-size:3rem;margin:0 0 8px}p{color:#5a7394;max-width:52ch}a{color:#3b82f6}pre{color:#f87171;text-align:left;max-width:80ch;overflow:auto;background:#101c30;padding:16px;border-radius:8px}</style>'
        . '</head><body><div><h1>500</h1><p>Something went wrong on our end. It has been logged — please try again in a moment.</p>'
        . '<a href="' . e(url('dashboard')) . '">Back to dashboard</a>'
        . ($debug ? '<pre>' . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>' : '')
        . '</div></body></html>';
});

// ── Security headers ──────────────────────────────────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    // HSTS only makes sense (and is only safe) over a connection that's
    // actually HTTPS right now — sending it on plain-HTTP local dev would be
    // a no-op at best and a confusing footgun at worst if this box is ever
    // reused for something HTTP-only. Checks both a direct TLS terminator
    // and the X-Forwarded-Proto a reverse proxy would set, matching how
    // COOKIE_SECURE is already conditioned on production's real APP_URL.
    $isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Content-Security-Policy:
    // 'unsafe-inline' for scripts is required because the dashboard embeds
    // the session-clock JS directly in the view template.
    // Inline styles are used by the login and 404 pages.
    // connect-src 'self' allows client-side fetch/XHR to THIS app's own
    // origin only (e.g. the dashboard's Live Market Overview poll against
    // /api/market-overview/quotes) — external API calls still can't be made
    // from the browser; every external call remains server-side only,
    // exactly as before. This was 'none' until the dashboard redesign
    // introduced its first same-origin AJAX endpoint.
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline'; " .
        "style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data:; " .
        "font-src 'self'; " .
        "connect-src 'self'; " .
        "frame-src 'none'; " .
        "object-src 'none'; " .
        "base-uri 'self'; " .
        "form-action 'self';"
    );
}

// ── Helpers & session ─────────────────────────────────────────────────────────
require_once app_path('Helpers/functions.php');
require_once app_path('Helpers/time.php');
require_once app_path('Helpers/auth.php');
require_once app_path('Helpers/market.php');

App\Core\Session::start();

// ── Domain events ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/events.php';

// ── Module registry (scaffolding only — see app/Modules/) ─────────────────────
require_once __DIR__ . '/modules.php';
