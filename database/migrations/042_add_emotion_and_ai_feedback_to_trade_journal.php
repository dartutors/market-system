<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * Phase 21 fast-follow: the Trade Journal audit found two real gaps against
 * the "AI-powered journal" spec — no emotion field, and coaching was only
 * ever an on-demand AGGREGATE report (JournalCoachingService::report()),
 * never a per-entry note attached to the trade itself. Additive only, same
 * ADD COLUMN IF NOT EXISTS convention as migration 026's widening.
 */
$pdo->exec("
    ALTER TABLE trade_journal
    ADD COLUMN IF NOT EXISTS emotion     VARCHAR(20) NULL AFTER direction,
    ADD COLUMN IF NOT EXISTS ai_feedback TEXT        NULL AFTER lessons_learned
");

echo 'Migration 042: trade_journal widened with emotion and ai_feedback fields.' . PHP_EOL;
