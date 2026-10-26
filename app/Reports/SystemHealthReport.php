<?php
declare(strict_types=1);
namespace App\Reports;

use App\Core\Database;
use App\Models\AiProviderLog;
use App\Models\BacktestSummary;
use App\Models\MarketDataProviderLog;
use App\Models\VisionSession;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * "System Health" report — every component check below reads a real
 * timestamp or a real success/failure count from an existing table; none of
 * it is synthetic uptime monitoring. Cron Jobs specifically has no DB
 * tracking table anywhere in this schema (checked all 34 migrations) — its
 * "health" is read from the log files each cron script already writes
 * (storage/logs/*.log mtime), which is a real, if coarser, signal.
 * Thresholds ("healthy" vs "stale") are simple heuristics disclosed in the
 * view, not an authoritative SLA.
 */
final class SystemHealthReport
{
    private const STALE_HOURS = 24;

    public static function build(): array
    {
        $now = new DateTimeImmutable('now');

        return [
            'database'    => self::checkDatabase(),
            'ai_apis'     => self::checkAiApis($now),
            'news'        => self::checkNews($now),
            'market_data' => self::checkMarketData(),
            'calendar'    => self::checkCalendar($now),
            'backtesting' => self::checkBacktesting($now),
            'vision'      => self::checkVision($now),
            'cron'        => self::checkCron($now),
        ];
    }

    private static function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            Database::connection()->query('SELECT 1');
            return ['status' => 'healthy', 'detail' => round((microtime(true) - $start) * 1000) . 'ms round-trip'];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    private static function checkAiApis(DateTimeImmutable $now): array
    {
        try {
            $recent = AiProviderLog::latest(20);
            if ($recent === []) {
                return ['status' => 'unknown', 'detail' => 'No AI calls logged yet.'];
            }
            $successes = count(array_filter($recent, static fn(array $r): bool => (int) $r['http_status'] >= 200 && (int) $r['http_status'] < 300));
            $successRate = round($successes / count($recent) * 100, 1);
            $lastAt = new DateTimeImmutable((string) $recent[0]['created_at']);
            $status = $successRate >= 80.0 ? 'healthy' : ($successRate >= 50.0 ? 'degraded' : 'down');
            return ['status' => $status, 'detail' => "{$successRate}% success over last " . count($recent) . " calls · last call " . self::ago($now, $lastAt)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private static function checkNews(DateTimeImmutable $now): array
    {
        try {
            $row = Database::connection()->query('SELECT MAX(created_at) AS latest, COUNT(*) AS total FROM news_items')->fetch(PDO::FETCH_ASSOC);
            if (empty($row['latest'])) {
                return ['status' => 'unknown', 'detail' => 'No news items recorded yet.'];
            }
            $lastAt = new DateTimeImmutable((string) $row['latest']);
            $status = self::hoursSince($now, $lastAt) <= self::STALE_HOURS ? 'healthy' : 'stale';
            return ['status' => $status, 'detail' => (int) $row['total'] . ' articles total · last ' . self::ago($now, $lastAt)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private static function checkMarketData(): array
    {
        try {
            $stats = MarketDataProviderLog::statsByProvider();
            if ($stats === []) {
                return ['status' => 'unknown', 'detail' => 'No market data calls logged yet.'];
            }
            $totalCalls = array_sum(array_column($stats, 'total_calls'));
            $totalSuccess = array_sum(array_column($stats, 'successes'));
            $rate = $totalCalls > 0 ? round($totalSuccess / $totalCalls * 100, 1) : 0.0;
            $status = $rate >= 90.0 ? 'healthy' : ($rate >= 60.0 ? 'degraded' : 'down');
            return ['status' => $status, 'detail' => "{$rate}% success across " . count($stats) . ' provider(s), ' . $totalCalls . ' calls'];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private static function checkCalendar(DateTimeImmutable $now): array
    {
        try {
            $row = Database::connection()->query('SELECT MAX(created_at) AS latest, COUNT(*) AS total FROM calendar_events')->fetch(PDO::FETCH_ASSOC);
            if (empty($row['latest'])) {
                return ['status' => 'unknown', 'detail' => 'No calendar events recorded yet.'];
            }
            $lastAt = new DateTimeImmutable((string) $row['latest']);
            $status = self::hoursSince($now, $lastAt) <= (self::STALE_HOURS * 7) ? 'healthy' : 'stale';
            return ['status' => $status, 'detail' => (int) $row['total'] . ' events on file · last added ' . self::ago($now, $lastAt)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private static function checkBacktesting(DateTimeImmutable $now): array
    {
        try {
            $recent = BacktestSummary::recent(1);
            if ($recent === []) {
                return ['status' => 'unknown', 'detail' => 'No backtests run yet.'];
            }
            $lastAt = new DateTimeImmutable((string) $recent[0]['created_at']);
            return ['status' => 'healthy', 'detail' => 'Last run ' . self::ago($now, $lastAt)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private static function checkVision(DateTimeImmutable $now): array
    {
        try {
            $recent = VisionSession::recent(1);
            if ($recent === []) {
                return ['status' => 'unknown', 'detail' => 'No AI Vision sessions yet.'];
            }
            $lastAt = new DateTimeImmutable((string) $recent[0]['created_at']);
            return ['status' => 'healthy', 'detail' => 'Last session ' . self::ago($now, $lastAt)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private static function checkCron(DateTimeImmutable $now): array
    {
        try {
            $lines = [];

            $scanRun = Database::connection()->query('SELECT MAX(created_at) AS latest FROM scan_runs')->fetch(PDO::FETCH_ASSOC);
            if (!empty($scanRun['latest'])) {
                $lastAt = new DateTimeImmutable((string) $scanRun['latest']);
                $lines[] = 'market_scan: last run ' . self::ago($now, $lastAt) . ' (scan_runs table)';
            } else {
                $lines[] = 'market_scan: no runs recorded yet';
            }

            foreach ([
                'calendar_refresh'  => 'calendar_refresh.log',
                'market_stream'     => 'market_stream.log',
                'consensus_refresh' => 'consensus_refresh.log',
                'weight_optimizer'  => 'weight_optimizer.log',
                'maintenance'       => 'maintenance.log',
            ] as $job => $logFile) {
                $path = base_path("storage/logs/{$logFile}");
                if (is_file($path)) {
                    $mtime = new DateTimeImmutable('@' . filemtime($path));
                    $mtime = $mtime->setTimezone($now->getTimezone());
                    $lines[] = "{$job}: log last written " . self::ago($now, $mtime) . ' (no DB tracking table exists for this job)';
                } else {
                    $lines[] = "{$job}: no log file found";
                }
            }

            $status = !empty($scanRun['latest']) && self::hoursSince($now, new DateTimeImmutable((string) $scanRun['latest'])) <= self::STALE_HOURS
                ? 'healthy' : 'stale';

            return ['status' => $status, 'detail' => implode(' · ', $lines)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'Check failed: ' . $e->getMessage()];
        }
    }

    /** @return array{headers: array<int, string>, rows: array<int, array<int, string>>} */
    public static function toTable(array $report): array
    {
        $rows = [];
        foreach ($report as $component => $result) {
            $rows[] = [ucwords(str_replace('_', ' ', (string) $component)), (string) $result['status'], (string) $result['detail']];
        }
        return ['headers' => ['Component', 'Status', 'Detail'], 'rows' => $rows];
    }

    private static function hoursSince(DateTimeImmutable $now, DateTimeImmutable $then): float
    {
        return abs($now->getTimestamp() - $then->getTimestamp()) / 3600;
    }

    private static function ago(DateTimeImmutable $now, DateTimeImmutable $then): string
    {
        $hours = self::hoursSince($now, $then);
        if ($hours < 1) {
            return round($hours * 60) . 'm ago';
        }
        if ($hours < 48) {
            return round($hours, 1) . 'h ago';
        }
        return round($hours / 24) . 'd ago';
    }
}
