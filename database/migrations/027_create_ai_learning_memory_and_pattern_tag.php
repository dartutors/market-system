<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Additive only — Phase 2.8 activates the previously-unused strategy_statistics
// and pattern_statistics tables (built in an earlier sprint, zero writers
// until now) and adds two new pieces:
//   1. trade_journal.setup_pattern — a curated trade-setup tag (see
//      App\Learning\SetupPatternCatalog) the user selects when logging/
//      closing a trade, letting pattern_statistics be populated from real
//      logged outcomes rather than needing a fragile cross-reference to
//      Vision's separately-uploaded chart screenshots.
//   2. ai_learning_memory — the "AI memory" table: append-only snapshots of
//      computed calibration/learning facts (confidence calibration today),
//      read cheaply by App\Decision\DecisionEngine instead of recomputing
//      an aggregate query on every decision.
$pdo->exec("
    ALTER TABLE trade_journal
    ADD COLUMN IF NOT EXISTS setup_pattern VARCHAR(50) NULL AFTER direction
");

try {
    $pdo->exec('CREATE INDEX idx_trade_journal_setup_pattern ON trade_journal (setup_pattern)');
} catch (\Throwable $e) {
    // Already exists from a prior run — MariaDB has no ADD INDEX IF NOT EXISTS.
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_learning_memory (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    memory_key         VARCHAR(100) NOT NULL,
    memory_value_json  TEXT         NOT NULL,
    sample_size        INT UNSIGNED NOT NULL DEFAULT 0,
    computed_at        DATETIME     NOT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_learning_memory_key     (memory_key),
    INDEX idx_ai_learning_memory_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 027: trade_journal.setup_pattern added, ai_learning_memory table created.' . PHP_EOL;
