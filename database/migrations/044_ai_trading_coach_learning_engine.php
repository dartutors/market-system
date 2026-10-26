<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * Phase 23 — AI Trading Coach & Continuous Learning Engine.
 *
 * trade_journal gets two new columns: a structured, scored report card
 * (Objective 4 — Trend Following/Risk Management/Entry Timing/Exit Timing/
 * Psychology/News Awareness/Session Selection, each 0-100, plus an overall
 * letter grade) computed rule-based on trade close, distinct from the
 * existing free-text ai_feedback column (one AI-generated paragraph, added
 * migration 042) — this is a structured breakdown, not a replacement.
 *
 * trader_achievements is new: real milestone unlocks (Objective 10),
 * persisted so "unlocked_at" is a genuine first-crossed timestamp, not
 * recomputed fresh (and potentially back-dated) on every dashboard load.
 *
 * No new table for "lessons" (Objective 8) — those are cheap to derive
 * live from already-loaded TradeJournal::stats() on each Learning
 * Dashboard render (real evidence, no AI call, no staleness to manage) —
 * see App\Services\AiTradingCoachService::personalInsights().
 */
$pdo->exec("
    ALTER TABLE trade_journal
    ADD COLUMN IF NOT EXISTS report_card_json  TEXT        NULL AFTER ai_feedback,
    ADD COLUMN IF NOT EXISTS report_card_grade VARCHAR(5)  NULL AFTER report_card_json
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS trader_achievements (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    achievement_key  VARCHAR(50)  NOT NULL,
    context_json     TEXT         NULL,
    unlocked_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trader_achievements_key (achievement_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 044: trade_journal report card fields + trader_achievements table added.' . PHP_EOL;
