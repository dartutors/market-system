<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Section 12 "Watchlist" — global, not per-user (this app has no per-user
// data scoping anywhere else: journal, signals, calendar are all shared
// across the two admin accounts, so a per-user watchlist would be the only
// scoped table in the schema and add complexity nothing else needs).
$pdo->exec("
    CREATE TABLE IF NOT EXISTS watchlist_items (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        symbol       VARCHAR(20)  NOT NULL,
        market_label VARCHAR(50)  NOT NULL,
        category     VARCHAR(20)  NOT NULL DEFAULT 'forex',
        created_by   INT UNSIGNED NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_watchlist_symbol (symbol)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo 'Migration 034: watchlist_items table created (or already present).' . PHP_EOL;
