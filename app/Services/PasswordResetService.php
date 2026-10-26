<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Security;
use App\Models\PasswordReset;
use App\Models\User;
use DateTimeImmutable;

/**
 * Forgot/reset password. Deliberately silent about whether an email is
 * registered — requestReset() always returns void and does the same amount
 * of "nothing visible" work whether the account exists or not, so the
 * controller can show one generic message regardless (no user enumeration).
 *
 * Response-timing note: the real SMTP send only happens for a registered,
 * active, non-throttled email, and network round-trips to a mail server are
 * not free — without a floor, an observer could distinguish "registered" vs
 * "not" purely from how fast the request returned, even though the response
 * body is identical either way. enforceMinimumDuration() pads every code
 * path (found or not) up to the same minimum wall-clock time so that signal
 * is masked. It's a floor, not true constant-time — proportionate for this
 * app's actual threat model, not a formal timing-safety proof.
 */
final class PasswordResetService
{
    private const TOKEN_EXPIRY_MINUTES     = 60;
    private const REQUEST_THROTTLE_SECONDS = 120;
    private const MIN_DURATION_SECONDS     = 0.6;

    public function __construct(private NotificationService $notifier = new NotificationService())
    {
    }

    public function requestReset(string $email): void
    {
        $startedAt = microtime(true);
        $email = strtolower(trim($email));
        if ($email === '') {
            $this->enforceMinimumDuration($startedAt);
            return;
        }

        $user = User::findByEmail($email);
        if ($user === null || !(bool) $user['is_active']) {
            $this->enforceMinimumDuration($startedAt);
            return;
        }

        $lastRequest = PasswordReset::lastRequestedAt($email);
        if ($lastRequest !== null && (time() - strtotime($lastRequest)) < self::REQUEST_THROTTLE_SECONDS) {
            $this->enforceMinimumDuration($startedAt);
            return;
        }

        // Older outstanding tokens for this email are left to expire
        // naturally rather than deleted here — deleting-then-inserting made
        // two near-simultaneous requests race (request B's delete could wipe
        // out request A's already-emailed token before it was ever used).
        // completeReset() still deletes all outstanding tokens for the email
        // once one is actually used, so a used link can't be replayed.
        $rawToken  = Security::randomToken(32);
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = new DateTimeImmutable('+' . self::TOKEN_EXPIRY_MINUTES . ' minutes');
        PasswordReset::create($email, $tokenHash, $expiresAt);

        $link = url('reset-password') . '?token=' . urlencode($rawToken);
        $body = "A password reset was requested for your Market Signal System account.\n\n"
              . "Reset your password: {$link}\n\n"
              . 'This link expires in ' . self::TOKEN_EXPIRY_MINUTES . " minutes and can only be used once.\n"
              . "If you didn't request this, you can safely ignore this email — your password hasn't changed.";

        $this->notifier->sendTo($email, 'Reset your Market Signal System password', $body);
        $this->enforceMinimumDuration($startedAt);
    }

    private function enforceMinimumDuration(float $startedAt): void
    {
        $remaining = self::MIN_DURATION_SECONDS - (microtime(true) - $startedAt);
        if ($remaining > 0) {
            usleep((int) round($remaining * 1_000_000));
        }
    }

    public function completeReset(string $rawToken, string $newPassword): bool
    {
        $tokenHash = hash('sha256', $rawToken);
        $reset = PasswordReset::findValidByTokenHash($tokenHash);
        if ($reset === null) {
            return false;
        }

        $user = User::findByEmail((string) $reset['email']);
        if ($user === null) {
            return false;
        }

        User::updatePassword((int) $user['id'], Security::hashPassword($newPassword));
        PasswordReset::deleteByEmail((string) $reset['email']);

        return true;
    }
}
