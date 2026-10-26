<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

final class AiProviderLog
{
    public static function create(array $data): int
    {
        $sql = 'INSERT INTO ai_provider_logs
                    (service_name, provider, model, prompt_tokens, completion_tokens,
                     cost_usd, latency_ms, http_status, was_fallback_used, created_at)
                VALUES
                    (:service_name, :provider, :model, :prompt_tokens, :completion_tokens,
                     :cost_usd, :latency_ms, :http_status, :was_fallback_used, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function latest(int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_provider_logs ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Accuracy/usage rollup per provider — how often each provider actually
     * answered vs. was skipped/failed, and average latency.
     */
    public static function statsByProvider(): array
    {
        $stmt = Database::connection()->query(
            "SELECT
                provider,
                COUNT(*)                                                  AS total_attempts,
                SUM(CASE WHEN http_status = 200 THEN 1 ELSE 0 END)        AS successes,
                SUM(CASE WHEN was_fallback_used = 1 THEN 1 ELSE 0 END)    AS fallback_uses,
                AVG(latency_ms)                                           AS avg_latency_ms,
                SUM(cost_usd)                                             AS total_cost_usd
             FROM ai_provider_logs
             GROUP BY provider"
        );
        return $stmt->fetchAll() ?: [];
    }
}
