<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;

final class PasswordReset
{
    public static function create(string $email, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO password_resets (email, token_hash, expires_at, created_at) VALUES (:email, :hash, :expires, NOW())'
        );
        $stmt->execute([
            'email'   => $email,
            'hash'    => $tokenHash,
            'expires' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    public static function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM password_resets WHERE token_hash = :hash AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        return $stmt->fetch() ?: null;
    }

    /** Invalidates every outstanding token for an email — called after a successful reset so a used link can't be replayed. */
    public static function deleteByEmail(string $email): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM password_resets WHERE email = :email');
        $stmt->execute(['email' => $email]);
    }

    /** Housekeeping: rows past expires_at are already unusable (findValidByTokenHash excludes them) — this just reclaims the space. Returns rows deleted. */
    public static function deleteExpired(): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM password_resets WHERE expires_at <= NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Throttle guard: most recent request timestamp for this email, if any. */
    public static function lastRequestedAt(string $email): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT created_at FROM password_resets WHERE email = :email ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : (string) $row['created_at'];
    }
}
