<?php
declare(strict_types=1);
use App\Core\Database;
$pdo = Database::connection();
$pdo->exec("CREATE TABLE IF NOT EXISTS scan_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_symbol VARCHAR(50) NOT NULL,
    run_status VARCHAR(20) NOT NULL DEFAULT 'ok',
    details TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scan_runs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
