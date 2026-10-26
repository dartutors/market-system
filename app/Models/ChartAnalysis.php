<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class ChartAnalysis
{
    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO chart_analysis
                    (image_id, provider, model, trend, indicators_observed_json,
                     support_levels_json, resistance_levels_json, trendlines_json,
                     liquidity_zones_json, candlestick_patterns_json, chart_patterns_json,
                     chart_patterns_algorithmic_json,
                     summary, recommendation_decision, recommendation_confidence,
                     recommendation_reasoning, confidence, created_at)
                 VALUES
                    (:image_id, :provider, :model, :trend, :indicators_observed_json,
                     :support_levels_json, :resistance_levels_json, :trendlines_json,
                     :liquidity_zones_json, :candlestick_patterns_json, :chart_patterns_json,
                     :chart_patterns_algorithmic_json,
                     :summary, :recommendation_decision, :recommendation_confidence,
                     :recommendation_reasoning, :confidence, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function findByImageId(int $imageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM chart_analysis WHERE image_id = :image_id ORDER BY created_at DESC'
        );
        $stmt->bindValue(':image_id', $imageId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Joins uploaded_charts for timeframe/market_label, since chart_analysis
     * itself carries neither — needed for the AI Vision report's per-
     * timeframe breakdown and pattern tallying.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentWithChart(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 500): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ca.*, uc.timeframe, uc.market_label
             FROM chart_analysis ca
             JOIN uploaded_charts uc ON uc.id = ca.image_id
             WHERE ca.created_at BETWEEN :from AND :to
             ORDER BY ca.created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':from', $from->format('Y-m-d H:i:s'));
        $stmt->bindValue(':to', $to->format('Y-m-d H:i:s'));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
