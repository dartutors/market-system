<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Session-scoped CSRF protection.
 *
 * One token per session — generated on first access, persists until the
 * session is destroyed (logout or idle timeout).  Rotating on every
 * request breaks multi-tab usage and the browser back button, which is
 * unacceptable for a private single-user system.
 *
 * The protection guarantee: a cross-site attacker cannot read the token
 * from the victim's session, so forged form submissions will always fail.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = Security::randomToken(16);
        }
        return (string) $_SESSION['_csrf'];
    }

    /**
     * Constant-time comparison — safe against timing attacks.
     * Token is NOT rotated; it lives for the lifetime of the session.
     */
    public static function validate(?string $token): bool
    {
        if (!is_string($token) || !isset($_SESSION['_csrf'])) {
            return false;
        }
        return hash_equals((string) $_SESSION['_csrf'], $token);
    }
}
