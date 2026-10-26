<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Lightweight per-session sliding-window throttle for expensive/billed
 * endpoints (AI calls, heavy synchronous compute) — this app has no
 * dedicated rate-limiting infrastructure (no Redis, no queue), and
 * App\Services\LoginThrottleService is DB-backed and specific to failed
 * logins, so this is the session-based equivalent for everything else.
 * Not a defense against a determined attacker with multiple sessions —
 * proportionate to this app's actual threat model (a handful of
 * authenticated admins), same reasoning already applied to
 * NewsController::allowExplainCall(), which this class now backs too.
 */
final class RateLimiter
{
    /** @return bool True if the call is allowed (and is now recorded); false if the window's limit was already reached. */
    public static function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $sessionKey = 'rate_limit_' . $key;
        $now = time();

        $calls = $_SESSION[$sessionKey] ?? [];
        $calls = array_values(array_filter($calls, static fn(int $t): bool => ($now - $t) < $windowSeconds));

        if (count($calls) >= $limit) {
            $_SESSION[$sessionKey] = $calls;
            return false;
        }

        $calls[] = $now;
        $_SESSION[$sessionKey] = $calls;
        return true;
    }
}
