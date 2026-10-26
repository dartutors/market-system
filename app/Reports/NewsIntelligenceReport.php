<?php
declare(strict_types=1);
namespace App\Reports;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

final class NewsIntelligenceReport
{
    public static function build(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            return self::buildFromDatabase($from, $to);
        } catch (\Throwable $e) {
            error_log('[NewsIntelligenceReport] build failed: ' . $e->getMessage());
            return [
                'articles_processed' => 0,
                'by_bias'            => ['bullish' => 0, 'bearish' => 0, 'neutral' => 0],
                'by_source'          => [], 'sentiment_trend' => [], 'ai_summary_history' => [],
                'unavailable' => true,
            ];
        }
    }

    private static function buildFromDatabase(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $pdo = Database::connection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $bias = $pdo->prepare(
            "SELECT market_bias, COUNT(*) AS total FROM news_items
             WHERE created_at BETWEEN :from AND :to GROUP BY market_bias"
        );
        $bias->execute($params);
        $byBias = ['bullish' => 0, 'bearish' => 0, 'neutral' => 0];
        foreach ($bias->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string) $row['market_bias']);
            if (isset($byBias[$key])) {
                $byBias[$key] = (int) $row['total'];
            }
        }

        $bySource = $pdo->prepare(
            "SELECT source, COUNT(*) AS total, AVG(impact_score) AS avg_impact FROM news_items
             WHERE created_at BETWEEN :from AND :to GROUP BY source ORDER BY total DESC"
        );
        $bySource->execute($params);

        $trend = $pdo->prepare(
            "SELECT DATE(created_at) AS day,
                    SUM(CASE WHEN market_bias = 'bullish' THEN 1 ELSE 0 END) AS bullish,
                    SUM(CASE WHEN market_bias = 'bearish' THEN 1 ELSE 0 END) AS bearish,
                    SUM(CASE WHEN market_bias = 'neutral' THEN 1 ELSE 0 END) AS neutral
             FROM news_items WHERE created_at BETWEEN :from AND :to
             GROUP BY DATE(created_at) ORDER BY day ASC"
        );
        $trend->execute($params);

        $totalCount = $pdo->prepare('SELECT COUNT(*) FROM news_items WHERE created_at BETWEEN :from AND :to');
        $totalCount->execute($params);

        $summaries = $pdo->prepare(
            "SELECT provider, model, market_bias, confidence, created_at FROM ai_summaries
             WHERE created_at BETWEEN :from AND :to ORDER BY created_at DESC LIMIT 20"
        );
        $summaries->execute($params);

        return [
            'articles_processed' => (int) $totalCount->fetchColumn(),
            'by_bias'            => $byBias,
            'by_source'          => $bySource->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'sentiment_trend'    => $trend->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'ai_summary_history' => $summaries->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    public static function toTable(array $report): array
    {
        return [
            'headers' => ['Source', 'Articles', 'Avg Impact Score'],
            'rows' => array_map(
                static fn(array $r): array => [$r['source'], (int) $r['total'], round((float) $r['avg_impact'], 1)],
                $report['by_source']
            ),
        ];
    }
}
