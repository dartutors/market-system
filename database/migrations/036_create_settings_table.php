<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Generic key-value settings store — this app had zero DB-backed
// configuration before (everything was .env-only, confirmed before
// building this). Scoped narrowly for Branding right now (logo/favicon
// paths + a cache-busting version stamp); NOT a general Settings Center —
// that module (API keys, 2FA, etc.) was explicitly deferred. Secrets never
// belong in this table: API keys stay in .env per that decision.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(100) NOT NULL,
        setting_value TEXT         NULL,
        updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo 'Migration 036: settings table created (or already present).' . PHP_EOL;
