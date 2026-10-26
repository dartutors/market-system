<?php
declare(strict_types=1);
namespace App\Core;

final class Session
{
    private const IDLE_TIMEOUT = 7200; // 2 hours in seconds

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        session_name((string) env('SESSION_NAME', 'market_signal_session'));

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) env('COOKIE_SECURE', false),
            'httponly' => (bool) env('COOKIE_HTTPONLY', true),
            'samesite' => (string) env('COOKIE_SAMESITE', 'Lax'),
        ]);

        session_start();

        // ── Server-side idle timeout ─────────────────────────────────────────
        if (
            isset($_SESSION['_last_activity']) &&
            (time() - (int) $_SESSION['_last_activity']) > self::IDLE_TIMEOUT
        ) {
            self::destroy();
            session_start();
        }

        $_SESSION['_last_activity'] = time();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
