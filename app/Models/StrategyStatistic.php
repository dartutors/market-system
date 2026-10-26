<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class StrategyStatistic
{
    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO strategy_statistics
                    (market_label, timeframe, strategy_tag, period_start, period_end,
                     win_rate, avg_r, max_drawdown_r, sample_size, created_at)
                 VALUES
                    (:market_label, :timeframe, :strategy_tag, :period_start, :period_end,
                     :win_rate, :avg_r, :max_drawdown_r, :sample_size, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function latestForMarket(string $marketLabel, string $timeframe, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM strategy_statistics
             WHERE market_label = :market_label AND timeframe = :timeframe
             ORDER BY period_end DESC LIMIT :limit'
        );
        $stmt->bindValue(':market_label', $marketLabel);
        $stmt->bindValue(':timeframe', $timeframe);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /** All recent rows regardless of market/timeframe — for a cross-market overview. */
    public static function latest(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM strategy_statistics ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
