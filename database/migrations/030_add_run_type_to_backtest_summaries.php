<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Additive only. Phase 3.1's Advanced Backtesting reuses backtest_summaries
// (migration 012) rather than creating a parallel table — run_type
// distinguishes the existing single-strategy quick backtest ('basic',
// the default, so the pre-existing BacktestSummary::create() call site
// needs no change) from a Phase 3.1 multi-strategy/walk-forward/Monte-Carlo
// run ('advanced'). stats_json is widened from TEXT (64KB) to MEDIUMTEXT
// (16MB) because an advanced payload bundles strategy comparison,
// walk-forward windows, parameter-optimization grid, Monte Carlo
// percentiles, the full equity curve, and per-trade replay snippets.
$pdo->exec("
    ALTER TABLE backtest_summaries
    ADD COLUMN IF NOT EXISTS run_type VARCHAR(20) NOT NULL DEFAULT 'basic' AFTER period_days
");
$pdo->exec("
    ALTER TABLE backtest_summaries
    MODIFY COLUMN stats_json MEDIUMTEXT NOT NULL
");

echo 'Migration 030: backtest_summaries widened with run_type + MEDIUMTEXT stats_json.' . PHP_EOL;
