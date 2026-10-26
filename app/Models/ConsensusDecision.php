<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

final class ConsensusDecision
{
    public static function create(array $data): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO consensus_decisions
                (market_label, market_symbol, overall_decision, consensus_score, agreement_percentage,
                 market_quality_score, risk_level, sources_json, disagreement_json, conflicts_json,
                 trade_plan_json, invalidation_conditions_json, next_review_at,
                 reliability_grade, reliability_sample_size, generated_at)
             VALUES
                (:market_label, :market_symbol, :overall_decision, :consensus_score, :agreement_percentage,
                 :market_quality_score, :risk_level, :sources_json, :disagreement_json, :conflicts_json,
                 :trade_plan_json, :invalidation_conditions_json, :next_review_at,
                 :reliability_grade, :reliability_sample_size, NOW())'
        );
        $stmt->execute([
            'market_label'          => (string) $data['market_label'],
            'market_symbol'         => (string) $data['market_symbol'],
            'overall_decision'      => (string) $data['overall_decision'],
            'consensus_score'       => (float) $data['consensus_score'],
            'agreement_percentage'  => (float) $data['agreement_percentage'],
            'market_quality_score'  => (float) $data['market_quality_score'],
            'risk_level'            => (string) $data['risk_level'],
            'sources_json'          => (string) $data['sources_json'],
            'conflicts_json'                => $data['conflicts_json'] ?? null,
            'invalidation_conditions_json'   => $data['invalidation_conditions_json'] ?? null,
            'next_review_at'                 => $data['next_review_at'] ?? null,
            'disagreement_json'     => (string) $data['disagreement_json'],
            'trade_plan_json'       => (string) $data['trade_plan_json'],
            'reliability_grade'          => $data['reliability_grade'] ?? null,
            'reliability_sample_size'    => $data['reliability_sample_size'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Most recent decision for a market, or null if none yet / stale beyond $maxAgeMinutes. */
    public static function latestForMarket(string $marketLabel, int $maxAgeMinutes = 30): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM consensus_decisions
             WHERE market_label = :label AND generated_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
             ORDER BY generated_at DESC LIMIT 1'
        );
        $stmt->bindValue(':label', $marketLabel);
        $stmt->bindValue(':minutes', max(1, $maxAgeMinutes), PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Latest decision per market, regardless of staleness — used for display fallback when a refresh hasn't run recently. */
    public static function latestForMarketAnyAge(string $marketLabel): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM consensus_decisions WHERE market_label = :label ORDER BY generated_at DESC LIMIT 1'
        );
        $stmt->bindValue(':label', $marketLabel);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string, array> market_label => latest row, for every label in $marketLabels that has one */
    public static function latestForMarkets(array $marketLabels): array
    {
        if ($marketLabels === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($marketLabels), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT cd.* FROM consensus_decisions cd
             INNER JOIN (
                 SELECT market_label, MAX(generated_at) AS latest_at
                 FROM consensus_decisions
                 WHERE market_label IN ({$placeholders})
                 GROUP BY market_label
             ) latest ON latest.market_label = cd.market_label AND latest.latest_at = cd.generated_at"
        );
        $stmt->execute(array_values($marketLabels));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['market_label']] = $row;
        }
        return $indexed;
    }

    /** Housekeeping: keeps only the most recent $keepPerMarket rows per market. Returns rows deleted. */
    public static function pruneOldRows(int $keepPerMarket = 200): int
    {
        $pdo = Database::connection();
        $labels = $pdo->query('SELECT DISTINCT market_label FROM consensus_decisions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $deleted = 0;

        foreach ($labels as $label) {
            $stmt = $pdo->prepare(
                'DELETE FROM consensus_decisions
                 WHERE market_label = :label
                   AND id NOT IN (
                       SELECT id FROM (
                           SELECT id FROM consensus_decisions
                           WHERE market_label = :label2
                           ORDER BY generated_at DESC LIMIT :keep
                       ) keep_ids
                   )'
            );
            $stmt->bindValue(':label', $label);
            $stmt->bindValue(':label2', $label);
            $stmt->bindValue(':keep', $keepPerMarket, PDO::PARAM_INT);
            $stmt->execute();
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * Decisions with a real trade plan (BUY/SELL, entry/stop/target all
     * set), no outcome yet, and past their own expected duration — ready
     * for App\Consensus\OutcomeResolver to check real forward price action
     * against. Bounded lookback (14 days) so a resolver run doesn't keep
     * retrying something whose trade plan is too stale to mean anything.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendingOutcomeResolution(int $maxLookbackDays = 14): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM consensus_decisions
             WHERE outcome IS NULL
               AND overall_decision IN ('BUY', 'SELL')
               AND trade_plan_json IS NOT NULL AND trade_plan_json != 'null'
               AND generated_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             ORDER BY generated_at ASC
             LIMIT 200"
        );
        $stmt->bindValue(':days', max(1, $maxLookbackDays), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function markOutcome(int $id, string $outcome): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE consensus_decisions SET outcome = :outcome, resolved_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['outcome' => $outcome, 'id' => $id]);
    }

    /**
     * Every RESOLVED decision (outcome is win/loss/timeout) within the
     * window — the dataset App\Consensus\DecisionPerformanceAnalyzer reads
     * to compute per-source accuracy. Timeout counts as resolved (a
     * decision that never hit target or stop within its expected window is
     * real information — it just isn't a clean win/loss).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolvedDecisions(int $days = 90): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM consensus_decisions
             WHERE outcome IS NOT NULL
               AND generated_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             ORDER BY generated_at DESC"
        );
        $stmt->bindValue(':days', max(1, $days), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, mixed>> Most recent decisions across all markets, for a decision-history view. */
    public static function recent(int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM consensus_decisions ORDER BY generated_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Single decision by id — the AI Decision Replay feature's lookup. */
    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM consensus_decisions WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
