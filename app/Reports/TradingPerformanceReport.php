<?php
declare(strict_types=1);
namespace App\Reports;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * "AI Trading Performance" report. Everything here reads directly from
 * signal_events (this app's only record of what the system actually
 * signaled) plus strategy_statistics for the two figures signal_events
 * can't answer on its own (strategy accuracy, risk/reward — signal_events
 * has no numeric risk column, only a free-text risk_warning string, so
 * "Average Risk" is honestly sourced from strategy_statistics.avg_r
 * instead of invented from something that isn't there).
 */
final class TradingPerformanceReport
{
    public static function build(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            return self::buildFromDatabase($from, $to);
        } catch (\Throwable $e) {
            error_log('[TradingPerformanceReport] build failed: ' . $e->getMessage());
            return self::emptyReport();
        }
    }

    private static function emptyReport(): array
    {
        return [
            'total' => 0, 'buy_count' => 0, 'sell_count' => 0, 'wait_count' => 0,
            'win_rate' => null, 'loss_rate' => null, 'avg_confidence' => null,
            'avg_risk_reward' => null, 'strategy_accuracy' => null,
            'best_pair' => null, 'worst_pair' => null, 'daily' => [],
            'unavailable' => true,
        ];
    }

    private static function buildFromDatabase(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $pdo = Database::connection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $totals = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN action = 'BUY' THEN 1 ELSE 0 END) AS buy_count,
                SUM(CASE WHEN action = 'SELL' THEN 1 ELSE 0 END) AS sell_count,
                SUM(CASE WHEN action = 'WAIT' THEN 1 ELSE 0 END) AS wait_count,
                SUM(CASE WHEN outcome IS NOT NULL THEN 1 ELSE 0 END) AS with_outcome,
                SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses,
                AVG(confidence) AS avg_confidence
             FROM signal_events
             WHERE created_at BETWEEN :from AND :to"
        );
        $totals->execute($params);
        $t = $totals->fetch(PDO::FETCH_ASSOC) ?: [];

        $withOutcome = (int) ($t['with_outcome'] ?? 0);
        $winRate  = $withOutcome > 0 ? round((int) $t['wins']   / $withOutcome * 100, 1) : null;
        $lossRate = $withOutcome > 0 ? round((int) $t['losses'] / $withOutcome * 100, 1) : null;

        $byPair = $pdo->prepare(
            "SELECT market_label,
                    COUNT(*) AS total,
                    SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) AS wins
             FROM signal_events
             WHERE created_at BETWEEN :from AND :to AND outcome IS NOT NULL
             GROUP BY market_label"
        );
        $byPair->execute($params);
        $bestPair = null; $worstPair = null; $bestRate = -1.0; $worstRate = 999.0;
        foreach ($byPair->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rate = (int) $row['total'] > 0 ? (int) $row['wins'] / (int) $row['total'] * 100 : 0.0;
            if ($rate > $bestRate)  { $bestRate  = $rate; $bestPair  = $row['market_label']; }
            if ($rate < $worstRate) { $worstRate = $rate; $worstPair = $row['market_label']; }
        }

        $daily = $pdo->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total,
                    SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) AS wins,
                    SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses
             FROM signal_events
             WHERE created_at BETWEEN :from AND :to
             GROUP BY DATE(created_at) ORDER BY day ASC"
        );
        $daily->execute($params);
        $dailyRows = $daily->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $strategyStats = $pdo->query('SELECT win_rate, avg_r FROM strategy_statistics')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $strategyAccuracy = $strategyStats !== []
            ? round(array_sum(array_column($strategyStats, 'win_rate')) / count($strategyStats), 1)
            : null;
        $avgRiskReward = $strategyStats !== []
            ? round(array_sum(array_column($strategyStats, 'avg_r')) / count($strategyStats), 2)
            : null;

        return [
            'total'             => (int) ($t['total'] ?? 0),
            'buy_count'         => (int) ($t['buy_count'] ?? 0),
            'sell_count'        => (int) ($t['sell_count'] ?? 0),
            'wait_count'        => (int) ($t['wait_count'] ?? 0),
            'win_rate'          => $winRate,
            'loss_rate'         => $lossRate,
            'avg_confidence'    => $t['avg_confidence'] !== null ? round((float) $t['avg_confidence'], 1) : null,
            'avg_risk_reward'   => $avgRiskReward,
            'strategy_accuracy' => $strategyAccuracy,
            'best_pair'         => $bestPair,
            'worst_pair'        => $worstPair,
            'daily'             => $dailyRows,
        ];
    }

    /** @return array{headers: array<int,string>, rows: array<int, array<int, mixed>>} */
    public static function toTable(array $report): array
    {
        return [
            'headers' => ['Day', 'Signals', 'Wins', 'Losses'],
            'rows' => array_map(
                static fn(array $r): array => [$r['day'], (int) $r['total'], (int) $r['wins'], (int) $r['losses']],
                $report['daily']
            ),
        ];
    }
}
