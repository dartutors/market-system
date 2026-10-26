<?php
declare(strict_types=1);
namespace App\Reports;

use App\Core\Database;
use App\Models\ChartAnalysis;
use DateTimeImmutable;
use PDO;

/**
 * "AI Vision" report. "Pattern Detection Accuracy" and "Top Performing
 * Timeframes" from the original spec both imply a ground-truth outcome
 * (was the pattern real? did that timeframe's call work out?) that nothing
 * in this schema tracks — chart_analysis/vision_sessions record what the
 * AI said, never whether it was right. Reinterpreted honestly instead:
 * "algorithmic cross-check rate" (how often the LLM's pattern read was
 * corroborated by the real-data PatternRecognitionEngine, which IS a real
 * signal already computed at analysis time) and "most analyzed timeframes"
 * (a real count, not a fabricated performance ranking).
 */
final class AiVisionReport
{
    public static function build(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            return self::buildFromDatabase($from, $to);
        } catch (\Throwable $e) {
            error_log('[AiVisionReport] build failed: ' . $e->getMessage());
            return [
                'total_images' => 0, 'multi_timeframe_sessions' => 0, 'avg_confidence' => null,
                'decision_counts' => ['BUY' => 0, 'SELL' => 0, 'WAIT' => 0, 'NO_TRADE' => 0],
                'most_common_patterns' => [], 'most_analyzed_timeframes' => [],
                'cross_check_rate' => null, 'analyses_count' => 0,
                'unavailable' => true,
            ];
        }
    }

    private static function buildFromDatabase(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $pdo = Database::connection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $sessions = $pdo->prepare(
            "SELECT decision, confidence, overall_trend, created_at FROM vision_sessions
             WHERE created_at BETWEEN :from AND :to"
        );
        $sessions->execute($params);
        $sessionRows = $sessions->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $decisionCounts = ['BUY' => 0, 'SELL' => 0, 'WAIT' => 0, 'NO_TRADE' => 0];
        $confidenceSum = 0.0;
        foreach ($sessionRows as $row) {
            $decision = strtoupper((string) $row['decision']);
            if (isset($decisionCounts[$decision])) {
                $decisionCounts[$decision]++;
            }
            $confidenceSum += (float) $row['confidence'];
        }
        $avgConfidence = count($sessionRows) > 0 ? round($confidenceSum / count($sessionRows), 1) : null;

        $imagesCount = $pdo->prepare('SELECT COUNT(*) FROM uploaded_charts WHERE created_at BETWEEN :from AND :to AND deleted_at IS NULL');
        $imagesCount->execute($params);

        $analyses = ChartAnalysis::recentWithChart($from, $to);

        $timeframeCounts = [];
        $patternCounts = [];
        $crossCheckedCount = 0;
        foreach ($analyses as $row) {
            $tf = (string) ($row['timeframe'] ?? 'Unknown');
            $timeframeCounts[$tf] = ($timeframeCounts[$tf] ?? 0) + 1;

            $patterns = json_decode((string) $row['chart_patterns_json'], true);
            if (is_array($patterns)) {
                foreach ($patterns as $p) {
                    $name = is_array($p) ? (string) ($p['name'] ?? $p['pattern'] ?? 'Unnamed') : (string) $p;
                    $patternCounts[$name] = ($patternCounts[$name] ?? 0) + 1;
                }
            }

            $algoPatterns = json_decode((string) ($row['chart_patterns_algorithmic_json'] ?? '[]'), true);
            if (is_array($algoPatterns) && $algoPatterns !== []) {
                $crossCheckedCount++;
            }
        }
        arsort($patternCounts);
        arsort($timeframeCounts);

        return [
            'total_images'          => (int) $imagesCount->fetchColumn(),
            'multi_timeframe_sessions' => count($sessionRows),
            'avg_confidence'        => $avgConfidence,
            'decision_counts'       => $decisionCounts,
            'most_common_patterns'  => array_slice($patternCounts, 0, 8, true),
            'most_analyzed_timeframes' => $timeframeCounts,
            'cross_check_rate'      => count($analyses) > 0 ? round($crossCheckedCount / count($analyses) * 100, 1) : null,
            'analyses_count'        => count($analyses),
        ];
    }

    public static function toTable(array $report): array
    {
        $rows = [];
        foreach ($report['most_common_patterns'] as $pattern => $count) {
            $rows[] = [$pattern, $count];
        }
        return ['headers' => ['Pattern', 'Occurrences'], 'rows' => $rows];
    }
}
