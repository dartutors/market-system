<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
    ALTER TABLE signal_events
    ADD COLUMN IF NOT EXISTS outcome       VARCHAR(20)  DEFAULT NULL    AFTER status,
    ADD COLUMN IF NOT EXISTS outcome_notes TEXT         NULL            AFTER outcome,
    ADD COLUMN IF NOT EXISTS closed_at     DATETIME     NULL            AFTER outcome_notes
");

echo 'Migration 009: outcome columns added to signal_events.' . PHP_EOL;
