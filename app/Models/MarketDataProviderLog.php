<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Structurally mirrors App\Models\AiProviderLog — same "one row per
 * external call, cheap GROUP BY rollup" shape — for market-data provider
 * calls (Deriv/Yahoo/cache) instead of AI calls. Written exclusively by
 * App\MarketData\Manager\MarketDataManager.
 */
final class MarketDataProviderLog
{
    public static function create(array $data): int
    {
        $sql = 'INSERT INTO market_data_provider_logs
                    (operation, symbol, timeframe, provider_used, primary_provider,
                     was_fallback, was_cache, success, latency_ms, error_message, created_at)
                VALUES
                    (:operation, :symbol, :timeframe, :provider_used, :primary_provider,
                     :was_fallback, :was_cache, :success, :latency_ms, :error_message, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function latest(int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM market_data_provider_logs ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /** Reliability/latency rollup per provider, plus fallback frequency — the "diagnose data quality issues" view. */
    public static function statsByProvider(): array
    {
        $stmt = Database::connection()->query(
            "SELECT
                provider_used,
                COUNT(*)                                              AS total_calls,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END)          AS successes,
                SUM(CASE WHEN was_fallback = 1 THEN 1 ELSE 0 END)     AS fallback_uses,
                SUM(CASE WHEN was_cache = 1 THEN 1 ELSE 0 END)        AS cache_serves,
                AVG(latency_ms)                                       AS avg_latency_ms
             FROM market_data_provider_logs
             GROUP BY provider_used
             ORDER BY total_calls DESC"
        );
        return $stmt->fetchAll() ?: [];
    }

    /** Most recent fallback/failure events — the "diagnose data quality issues" drill-down. */
    public static function recentFallbackEvents(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM market_data_provider_logs
             WHERE was_fallback = 1 OR success = 0
             ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
