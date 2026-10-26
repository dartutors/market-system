<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * Decision Intelligence Engine (this phase): extends the existing
 * consensus_decisions table rather than creating a second, near-duplicate
 * table — App\Services\DecisionIntelligenceEngine is the evolution of
 * App\Consensus\ConsensusEngine (same underlying evidence adapters/scorer),
 * not a competing parallel system, so its output belongs in the same
 * history table. Adds: structured conflicts (distinct from the existing
 * disagreement_json, which is just "which sources dissented" — conflicts
 * are named, specific rule matches from App\Consensus\ConflictDetector),
 * invalidation conditions, a next-review timestamp, and outcome tracking
 * (Objective 8/9 — resolved after the fact by comparing real forward price
 * action against the stored trade plan).
 */
$pdo->exec("
    ALTER TABLE consensus_decisions
    ADD COLUMN IF NOT EXISTS conflicts_json               TEXT     NULL AFTER disagreement_json,
    ADD COLUMN IF NOT EXISTS invalidation_conditions_json  TEXT     NULL AFTER trade_plan_json,
    ADD COLUMN IF NOT EXISTS next_review_at                DATETIME NULL AFTER invalidation_conditions_json,
    ADD COLUMN IF NOT EXISTS outcome                       VARCHAR(20) NULL AFTER next_review_at,
    ADD COLUMN IF NOT EXISTS resolved_at                   DATETIME NULL AFTER outcome
");

$indexExists = $pdo->query("SHOW INDEX FROM consensus_decisions WHERE Key_name = 'idx_consensus_decisions_outcome'")->fetch();
if (!$indexExists) {
    $pdo->exec("ALTER TABLE consensus_decisions ADD INDEX idx_consensus_decisions_outcome (outcome)");
}
$indexExists = $pdo->query("SHOW INDEX FROM consensus_decisions WHERE Key_name = 'idx_consensus_decisions_review'")->fetch();
if (!$indexExists) {
    $pdo->exec("ALTER TABLE consensus_decisions ADD INDEX idx_consensus_decisions_review (next_review_at)");
}

echo 'Migration 043: consensus_decisions extended with conflicts/invalidation/review/outcome fields.' . PHP_EOL;
