<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class BacktestSummary
{
    public static function create(array $data): int
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO backtest_summaries
                (symbol, timeframe, period_days, run_type, decision, confidence, summary_text, stats_json, provider, created_at)
             VALUES
                (:symbol, :timeframe, :period_days, :run_type, :decision, :confidence, :summary_text, :stats_json, :provider, NOW())'
        );
        $stmt->execute([
            'symbol'       => (string) ($data['symbol']       ?? ''),
            'timeframe'    => (string) ($data['timeframe']    ?? ''),
            'period_days'  => (int)    ($data['period_days']  ?? 0),
            'run_type'     => (string) ($data['run_type']     ?? 'basic'),
            'decision'     => (string) ($data['decision']     ?? 'WAIT'),
            'confidence'   => (float)  ($data['confidence']   ?? 0.0),
            'summary_text' => (string) ($data['summary_text'] ?? ''),
            'stats_json'   => (string) ($data['stats_json']   ?? '{}'),
            'provider'     => (string) ($data['provider']     ?? 'rule_based'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Returns the most recent summary for the given symbol + timeframe. */
    public static function latestForSymbol(string $symbol, string $timeframe): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM backtest_summaries
             WHERE symbol = :symbol AND timeframe = :timeframe
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute(['symbol' => $symbol, 'timeframe' => $timeframe]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $decoded       = json_decode((string) ($row['stats_json'] ?? '{}'), true);
        $row['stats']  = \is_array($decoded) ? $decoded : [];
        return $row;
    }

    public static function recent(int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM backtest_summaries ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
