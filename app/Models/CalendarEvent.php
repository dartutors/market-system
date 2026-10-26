<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class CalendarEvent
{
    private const VALID_IMPACTS    = ['high', 'medium', 'low'];
    private const VALID_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD'];

    public static function all(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM calendar_events ORDER BY scheduled_at ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function upcoming(int $days = 14): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM calendar_events
             WHERE scheduled_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND scheduled_at <= DATE_ADD(NOW(), INTERVAL :days DAY)
             ORDER BY scheduled_at ASC'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Returns true if an event with the same title, currency, and
     * scheduled_at already exists — used to prevent duplicates on refresh.
     */
    public static function exists(string $title, string $currency, string $scheduledAt): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM calendar_events
             WHERE title = :title
               AND currency = :currency
               AND scheduled_at = :scheduled_at
             LIMIT 1'
        );
        $stmt->execute([
            'title'        => trim($title),
            'currency'     => strtoupper(trim($currency)),
            'scheduled_at' => $scheduledAt,
        ]);
        return (bool) $stmt->fetch();
    }

    /**
     * Deletes events whose scheduled_at is older than $olderThanDays days.
     * Returns the number of rows deleted.
     */
    public static function deleteExpired(int $olderThanDays = 30): int
    {
        $days = max(1, $olderThanDays);
        $stmt = Database::connection()->prepare(
            'DELETE FROM calendar_events
             WHERE scheduled_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->rowCount();
    }

    public static function create(array $data): int
    {
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        $impact   = strtolower(trim((string) ($data['impact']   ?? 'high')));

        if (!\in_array($currency, self::VALID_CURRENCIES, true)) {
            throw new \InvalidArgumentException("Invalid currency: {$currency}");
        }
        if (!\in_array($impact, self::VALID_IMPACTS, true)) {
            $impact = 'high';
        }

        // INSERT IGNORE against uq_calendar_events_identity (migration 038)
        // backstops the exists()-then-create() race at the DB level: the
        // web "run refresh now" endpoint and the cron job both call this
        // with no lock between them, so two near-simultaneous runs could
        // otherwise both pass exists() before either commits.
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO calendar_events (title, currency, impact, scheduled_at, created_at)
             VALUES (:title, :currency, :impact, :scheduled_at, NOW())'
        );
        $stmt->execute([
            'title'        => trim((string) ($data['title']        ?? '')),
            'currency'     => $currency,
            'impact'       => $impact,
            'scheduled_at' => (string) ($data['scheduled_at'] ?? ''),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM calendar_events WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function validImpacts(): array    { return self::VALID_IMPACTS; }
    public static function validCurrencies(): array { return self::VALID_CURRENCIES; }
}
