<?php
declare(strict_types=1);
namespace App\Reports;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * "Market Scanner" report. scan_runs only has (market_symbol, run_status,
 * details, created_at) — no duration or per-scan signal count columns (see
 * ScanRun model/migration) — so "Scans Executed" and pair breakdown come
 * from scan_runs, while "Signals Generated" and "Pair Rankings" come from
 * signal_events instead. "Technical Indicator Statistics" from the original
 * spec has no backing table anywhere (confirmed against BacktestSummary too)
 * and is honestly omitted rather than invented.
 */
final class MarketScannerReport
{
    public static function build(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            return self::buildFromDatabase($from, $to);
        } catch (\Throwable $e) {
            error_log('[MarketScannerReport] build failed: ' . $e->getMessage());
            return [
                'scans_executed' => 0, 'scan_status' => [], 'signals_generated' => 0,
                'strongest_signals' => [], 'weakest_signals' => [], 'pair_rankings' => [],
                'unavailable' => true,
            ];
        }
    }

    private static function buildFromDatabase(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $pdo = Database::connection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $scans = $pdo->prepare(
            "SELECT run_status, COUNT(*) AS total FROM scan_runs
             WHERE created_at BETWEEN :from AND :to GROUP BY run_status"
        );
        $scans->execute($params);
        $scanStatus = $scans->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $scansExecuted = array_sum(array_column($scanStatus, 'total'));

        $signalsCount = $pdo->prepare('SELECT COUNT(*) FROM signal_events WHERE created_at BETWEEN :from AND :to');
        $signalsCount->execute($params);
        $signalsGenerated = (int) $signalsCount->fetchColumn();

        $strongest = $pdo->prepare(
            "SELECT market_label, action, confidence, created_at FROM signal_events
             WHERE created_at BETWEEN :from AND :to AND action IN ('BUY','SELL')
             ORDER BY confidence DESC LIMIT 5"
        );
        $strongest->execute($params);

        $weakest = $pdo->prepare(
            "SELECT market_label, action, confidence, created_at FROM signal_events
             WHERE created_at BETWEEN :from AND :to AND action IN ('BUY','SELL')
             ORDER BY confidence ASC LIMIT 5"
        );
        $weakest->execute($params);

        $ranking = $pdo->prepare(
            "SELECT market_label, COUNT(*) AS total,
                    SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) AS wins,
                    AVG(confidence) AS avg_confidence
             FROM signal_events WHERE created_at BETWEEN :from AND :to
             GROUP BY market_label ORDER BY total DESC"
        );
        $ranking->execute($params);

        return [
            'scans_executed'    => (int) $scansExecuted,
            'scan_status'       => $scanStatus,
            'signals_generated' => $signalsGenerated,
            'strongest_signals' => $strongest->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'weakest_signals'   => $weakest->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'pair_rankings'     => $ranking->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    public static function toTable(array $report): array
    {
        return [
            'headers' => ['Pair', 'Signals', 'Wins', 'Avg Confidence'],
            'rows' => array_map(
                static fn(array $r): array => [$r['market_label'], (int) $r['total'], (int) $r['wins'], round((float) $r['avg_confidence'], 1)],
                $report['pair_rankings']
            ),
        ];
    }
}
