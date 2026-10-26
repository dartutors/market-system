<?php
declare(strict_types=1);
use App\Core\Database;
$pdo = Database::connection();
$pdo->exec("CREATE TABLE IF NOT EXISTS signal_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_symbol VARCHAR(50) NOT NULL,
    market_label VARCHAR(50) NOT NULL,
    timeframe VARCHAR(20) NOT NULL,
    session_name VARCHAR(20) NOT NULL,
    action VARCHAR(20) NOT NULL,
    confidence DECIMAL(8,2) NOT NULL DEFAULT 0,
    entry_window_start DATETIME NULL,
    entry_window_end DATETIME NULL,
    hold_hours_min INT NOT NULL DEFAULT 0,
    hold_hours_max INT NOT NULL DEFAULT 0,
    stop_loss_hint VARCHAR(255) NOT NULL DEFAULT '',
    take_profit_hint VARCHAR(255) NOT NULL DEFAULT '',
    reasons TEXT NOT NULL,
    risk_warning TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    signal_date DATE NOT NULL,
    was_alert_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_signal_daily_unique (signal_date, market_label, session_name, action),
    INDEX idx_signal_date (signal_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
