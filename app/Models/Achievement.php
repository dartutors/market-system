<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class Achievement
{
    /** Idempotent — INSERT IGNORE against the unique key means unlocking an already-unlocked achievement is a harmless no-op, not a duplicate row. */
    public static function unlock(string $key, array $context = []): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO trader_achievements (achievement_key, context_json, unlocked_at)
             VALUES (:key, :context, NOW())'
        );
        $stmt->execute([
            'key'     => $key,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, array{unlocked_at:string, context:array}> keyed by achievement_key */
    public static function allUnlocked(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM trader_achievements ORDER BY unlocked_at ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['achievement_key']] = [
                'unlocked_at' => (string) $row['unlocked_at'],
                'context'     => json_decode((string) $row['context_json'], true) ?: [],
            ];
        }
        return $indexed;
    }

    public static function isUnlocked(string $key): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM trader_achievements WHERE achievement_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        return (bool) $stmt->fetchColumn();
    }
}
