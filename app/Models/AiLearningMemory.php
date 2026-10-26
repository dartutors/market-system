<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * The "AI memory" table (Phase 2.8): append-only snapshots of computed
 * learning facts, keyed by a memory_key (e.g. 'confidence_calibration').
 * Append-only rather than upsert so remember()'s own history doubles as a
 * trend view of how a learned fact evolved — read via history().
 * App\Decision\DecisionEngine reads only latest() at decision time (a cheap
 * indexed lookup), never recomputing the underlying aggregate query itself.
 */
final class AiLearningMemory
{
    public static function remember(string $key, array $value, int $sampleSize): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO ai_learning_memory (memory_key, memory_value_json, sample_size, computed_at)
             VALUES (:memory_key, :memory_value_json, :sample_size, NOW())'
        );
        $stmt->execute([
            'memory_key'        => $key,
            'memory_value_json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            'sample_size'       => max(0, $sampleSize),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @return array{memory_key:string, value:array, sample_size:int, computed_at:string}|null */
    public static function latest(string $key): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_learning_memory WHERE memory_key = :memory_key ORDER BY computed_at DESC LIMIT 1'
        );
        $stmt->bindValue(':memory_key', $key);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::decode($row) : null;
    }

    /** @return array<int, array{memory_key:string, value:array, sample_size:int, computed_at:string}> */
    public static function history(string $key, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_learning_memory WHERE memory_key = :memory_key ORDER BY computed_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':memory_key', $key);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function decode(array $row): array
    {
        $decoded = json_decode((string) $row['memory_value_json'], true);
        return [
            'memory_key'  => (string) $row['memory_key'],
            'value'       => \is_array($decoded) ? $decoded : [],
            'sample_size' => (int) $row['sample_size'],
            'computed_at' => (string) $row['computed_at'],
        ];
    }
}
