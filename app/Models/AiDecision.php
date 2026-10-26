<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

final class AiDecision
{
    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO ai_decisions
                    (market_label, timeframe, decision, confidence, reasoning,
                     conflicts, risk_level, provider, snapshot_id, created_at)
                 VALUES
                    (:market_label, :timeframe, :decision, :confidence, :reasoning,
                     :conflicts, :risk_level, :provider, :snapshot_id, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ai_decisions WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    public static function latestForMarket(string $marketLabel, int $limit = 5): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_decisions WHERE market_label = :market_label
             ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':market_label', $marketLabel);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function latest(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_decisions ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
