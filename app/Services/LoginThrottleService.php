<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use PDO;

final class LoginThrottleService
{
    public function allow(string $email, string $ip): bool
    {
        $windowMinutes = max(1, (int) env('LOGIN_WINDOW_MINUTES', 15));

        // DATE_SUB with a bound integer value — safe, no interpolation
        $sql = 'SELECT COUNT(*) AS total
                FROM login_attempts
                WHERE email      = :email
                  AND ip_address = :ip_address
                  AND attempted_at >= DATE_SUB(NOW(), INTERVAL :window MINUTE)';

        $stmt = Database::connection()->prepare($sql);
        $stmt->bindValue(':email',      strtolower(trim($email)));
        $stmt->bindValue(':ip_address', $ip);
        $stmt->bindValue(':window',     $windowMinutes, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0) < max(1, (int) env('LOGIN_MAX_ATTEMPTS', 5));
    }

    public function recordFailure(string $email, string $ip): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO login_attempts (email, ip_address, attempted_at)
             VALUES (:email, :ip_address, NOW())'
        );
        $stmt->execute([
            'email'      => strtolower(trim($email)),
            'ip_address' => $ip,
        ]);

        // Prune records older than 24 hours to prevent unbounded table growth
        Database::connection()->exec(
            'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
    }

    public function clear(string $email, string $ip): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM login_attempts WHERE email = :email AND ip_address = :ip_address'
        );
        $stmt->execute([
            'email'      => strtolower(trim($email)),
            'ip_address' => $ip,
        ]);
    }
}
