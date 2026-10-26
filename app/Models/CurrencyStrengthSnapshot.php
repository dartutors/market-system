<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class CurrencyStrengthSnapshot
{
    /** @param array<string, float> $scores currency => score */
    public static function recordAll(array $scores): void
    {
        if ($scores === []) {
            return;
        }
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO currency_strength_snapshots (currency, score, captured_at) VALUES (:currency, :score, NOW())');
        foreach ($scores as $currency => $score) {
            $stmt->execute(['currency' => $currency, 'score' => $score]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function history(string $currency, int $limit = 90): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT score, captured_at FROM currency_strength_snapshots
             WHERE currency = :currency ORDER BY captured_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':currency', $currency);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public static function earliestCapturedAt(): ?string
    {
        $stmt = Database::connection()->query('SELECT MIN(captured_at) AS earliest FROM currency_strength_snapshots');
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['earliest'] ?? null;
    }

    /**
     * Throttle guard so a snapshot isn't written on every dashboard page
     * load (which would just log noise) — at most one row-set per 15
     * minutes, driven by real page visits rather than a cron job this app
     * doesn't have.
     */
    public static function shouldRecord(): bool
    {
        $stmt = Database::connection()->query('SELECT MAX(captured_at) AS latest FROM currency_strength_snapshots');
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($row['latest'])) {
            return true;
        }
        return (time() - strtotime((string) $row['latest'])) >= 900;
    }

    /** Housekeeping: at one row-set per 15 minutes this table grows ~280K rows/year at full traffic — prune anything older than the retention window. Returns rows deleted. */
    public static function deleteOlderThan(int $days = 180): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM currency_strength_snapshots WHERE captured_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
