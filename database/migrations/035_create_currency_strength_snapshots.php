<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Section "Currency Strength Reports: history" — this app had never
// persisted currency-strength readings before (only computed live, on
// demand). This table starts collecting real snapshots from the moment
// this migration runs; it is never backfilled with invented past data, so
// early report views will honestly show a short history that grows over
// time rather than a fabricated multi-month trend.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS currency_strength_snapshots (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        currency     VARCHAR(3)    NOT NULL,
        score        DECIMAL(10,4) NOT NULL,
        captured_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_css_currency_captured (currency, captured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo 'Migration 035: currency_strength_snapshots table created (or already present).' . PHP_EOL;
