<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class MarketSnapshotRecord
{
    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO market_snapshots
                    (symbol, timeframe, ohlc_json, indicators_json, trend, momentum,
                     session_name, sentiment_score, economic_risk_json, created_at)
                 VALUES
                    (:symbol, :timeframe, :ohlc_json, :indicators_json, :trend, :momentum,
                     :session_name, :sentiment_score, :economic_risk_json, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function latestForSymbol(string $symbol, string $timeframe, int $limit = 5): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM market_snapshots
             WHERE symbol = :symbol AND timeframe = :timeframe
             ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':symbol', $symbol);
        $stmt->bindValue(':timeframe', $timeframe);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
