<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * Phase 24 — AI Self-Evaluation & Model Improvement.
 *
 * evidence_source_weights makes the 9 directional adapters' currently-
 * hardcoded WEIGHT constants (TechnicalVoteAdapter::WEIGHT = 2.0, etc.)
 * overridable at runtime — a prerequisite for "adaptive weighting" meaning
 * anything operationally. ConsensusScorer reads from here first, falling
 * back to each adapter's own constant when no row exists (so this table
 * starting empty is not a regression — see migration seed below, which
 * copies today's real constants in as the starting values).
 *
 * weight_change_proposals is the approval gate this phase explicitly
 * requires ("recommended changes should be transparent, reviewable, and
 * require administrator approval before they take effect") — a proposal
 * computed by WeightOptimizerService never touches evidence_source_weights
 * directly; only an explicit admin approve action does.
 *
 * consensus_decisions gets a reliability snapshot at decision time
 * (previously only computed on-demand for whichever market the dashboard
 * happened to show) — this phase's "for every recommendation, store...
 * Reliability" requirement.
 */
$pdo->exec("
CREATE TABLE IF NOT EXISTS evidence_source_weights (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key  VARCHAR(50)   NOT NULL,
    weight      DECIMAL(5,3)  NOT NULL,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_evidence_source_weights_key (source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS weight_change_proposals (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key       VARCHAR(50)   NOT NULL,
    current_weight   DECIMAL(5,3)  NOT NULL,
    proposed_weight  DECIMAL(5,3)  NOT NULL,
    rationale        TEXT          NOT NULL,
    evidence_json    TEXT          NOT NULL,
    sample_size      INT UNSIGNED  NOT NULL DEFAULT 0,
    status           VARCHAR(20)   NOT NULL DEFAULT 'pending',
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at       DATETIME      NULL,
    decided_by       INT UNSIGNED  NULL,
    INDEX idx_weight_change_proposals_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    ALTER TABLE consensus_decisions
    ADD COLUMN IF NOT EXISTS reliability_grade       VARCHAR(5)  NULL AFTER risk_level,
    ADD COLUMN IF NOT EXISTS reliability_sample_size  INT UNSIGNED NULL AFTER reliability_grade
");

// Seed today's real hardcoded weights as the starting values — additive
// only, INSERT IGNORE so re-running this migration never resets a weight
// an admin has since approved a change for.
$defaults = [
    'Technical'        => 2.0,
    'Claude'           => 1.5,
    'OpenAI'           => 1.5,
    'Vision'           => 1.5,
    'TrendAlignment'   => 1.25,
    'SmartMoney'       => 1.25,
    'CurrencyStrength' => 1.0,
    'StrategyLab'      => 1.0,
    'News'             => 0.75,
];
$stmt = $pdo->prepare('INSERT IGNORE INTO evidence_source_weights (source_key, weight) VALUES (:key, :weight)');
foreach ($defaults as $key => $weight) {
    $stmt->execute(['key' => $key, 'weight' => $weight]);
}

echo 'Migration 045: evidence_source_weights + weight_change_proposals created, consensus_decisions extended with reliability snapshot, default weights seeded.' . PHP_EOL;
