<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class PatternStatistic
{
    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO pattern_statistics
                    (pattern_name, market_label, timeframe, occurrences, win_rate, avg_r, created_at)
                 VALUES
                    (:pattern_name, :market_label, :timeframe, :occurrences, :win_rate, :avg_r, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function latestForPattern(string $patternName, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM pattern_statistics WHERE pattern_name = :pattern_name
             ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':pattern_name', $patternName);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /** All recent rows regardless of pattern — for a cross-pattern overview. */
    public static function latest(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM pattern_statistics ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
