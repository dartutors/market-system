<?php
declare(strict_types=1);
namespace App\Reports;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * "AI Usage & Cost" report, built entirely from ai_provider_logs — the one
 * table in this app that actually records every real AI provider call
 * (service, provider, tokens, cost, latency). "Cache Efficiency" from the
 * original spec is deliberately omitted: this table only logs calls that
 * actually reached a provider, so a cache *hit* never produces a row here —
 * there is no real cache-hit/miss ratio to compute from it, and fabricating
 * one would defeat the point of this whole report.
 */
final class AiUsageReport
{
    public static function build(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            return self::buildFromDatabase($from, $to);
        } catch (\Throwable $e) {
            error_log('[AiUsageReport] build failed: ' . $e->getMessage());
            return [
                'total_calls' => 0, 'total_cost' => 0.0, 'total_tokens' => 0,
                'by_provider' => [], 'by_service' => [], 'daily' => [], 'monthly' => [],
                'unavailable' => true,
            ];
        }
    }

    private static function buildFromDatabase(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $pdo = Database::connection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $byProvider = $pdo->prepare(
            "SELECT provider,
                    COUNT(*) AS calls,
                    SUM(prompt_tokens) AS prompt_tokens,
                    SUM(completion_tokens) AS completion_tokens,
                    SUM(cost_usd) AS cost_usd,
                    AVG(latency_ms) AS avg_latency_ms,
                    SUM(CASE WHEN http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) AS successes
             FROM ai_provider_logs
             WHERE created_at BETWEEN :from AND :to
             GROUP BY provider"
        );
        $byProvider->execute($params);
        $providers = $byProvider->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byService = $pdo->prepare(
            "SELECT service_name, COUNT(*) AS calls, SUM(cost_usd) AS cost_usd
             FROM ai_provider_logs WHERE created_at BETWEEN :from AND :to
             GROUP BY service_name ORDER BY calls DESC"
        );
        $byService->execute($params);
        $services = $byService->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $daily = $pdo->prepare(
            "SELECT DATE(created_at) AS day, SUM(cost_usd) AS cost_usd, COUNT(*) AS calls
             FROM ai_provider_logs WHERE created_at BETWEEN :from AND :to
             GROUP BY DATE(created_at) ORDER BY day ASC"
        );
        $daily->execute($params);
        $dailyRows = $daily->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $monthly = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, SUM(cost_usd) AS cost_usd, COUNT(*) AS calls
             FROM ai_provider_logs WHERE created_at BETWEEN :from AND :to
             GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC"
        );
        $monthly->execute($params);
        $monthlyRows = $monthly->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalCalls  = array_sum(array_column($providers, 'calls'));
        $totalCost   = array_sum(array_column($providers, 'cost_usd'));
        $totalTokens = array_sum(array_column($providers, 'prompt_tokens')) + array_sum(array_column($providers, 'completion_tokens'));

        return [
            'total_calls'  => (int) $totalCalls,
            'total_cost'   => round((float) $totalCost, 4),
            'total_tokens' => (int) $totalTokens,
            'by_provider'  => $providers,
            'by_service'   => $services,
            'daily'        => $dailyRows,
            'monthly'      => $monthlyRows,
        ];
    }

    public static function toTable(array $report): array
    {
        return [
            'headers' => ['Day', 'Calls', 'Cost (USD)'],
            'rows' => array_map(
                static fn(array $r): array => [$r['day'], (int) $r['calls'], number_format((float) $r['cost_usd'], 4)],
                $report['daily']
            ),
        ];
    }
}
