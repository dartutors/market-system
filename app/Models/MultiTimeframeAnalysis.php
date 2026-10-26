<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class MultiTimeframeAnalysis
{
    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO multi_timeframe_analyses
                    (market_label, overall_bias, confidence, breakdown_json, conflicts_json,
                     pullback_json, entry_confirmation_json, ai_summary_json, recommendation, recommendation_confidence, created_at)
                 VALUES
                    (:market_label, :overall_bias, :confidence, :breakdown_json, :conflicts_json,
                     :pullback_json, :entry_confirmation_json, :ai_summary_json, :recommendation, :recommendation_confidence, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data + [
            'pullback_json'             => null,
            'entry_confirmation_json'   => null,
            'ai_summary_json'           => null,
            'recommendation'            => 'NO_TRADE',
            'recommendation_confidence' => 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function latestForMarket(string $marketLabel, int $limit = 5): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM multi_timeframe_analyses WHERE market_label = :market_label
             ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':market_label', $marketLabel);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Most recent row per market in one query — the same batched "latest per
     * group" shape as App\Models\ConsensusDecision::latestForMarkets(),
     * replacing what was previously a query-per-market loop in
     * DashboardController::findLatestMultiTimeframe().
     *
     * @param array<int, string> $marketLabels
     * @return array<int, array<string, mixed>>
     */
    public static function latestForMarkets(array $marketLabels): array
    {
        if ($marketLabels === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($marketLabels), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT mta.* FROM multi_timeframe_analyses mta
             INNER JOIN (
                 SELECT market_label, MAX(created_at) AS latest_at
                 FROM multi_timeframe_analyses
                 WHERE market_label IN ({$placeholders})
                 GROUP BY market_label
             ) latest ON latest.market_label = mta.market_label AND latest.latest_at = mta.created_at"
        );
        $stmt->execute(array_values($marketLabels));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
