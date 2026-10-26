<?php
declare(strict_types=1);

use App\Core\Database;
use App\Config\EconomicCalendar;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS calendar_events (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255)                   NOT NULL,
    currency     CHAR(3)                        NOT NULL,
    impact       ENUM('high','medium','low')    NOT NULL DEFAULT 'high',
    scheduled_at DATETIME                       NOT NULL,
    created_at   DATETIME                       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_calendar_events_scheduled_at (scheduled_at),
    INDEX idx_calendar_events_currency     (currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Seed from static config only if table is empty
$count = (int) $pdo->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn();

if ($count === 0) {
    $stmt = $pdo->prepare(
        'INSERT INTO calendar_events (title, currency, impact, scheduled_at)
         VALUES (:title, :currency, :impact, :scheduled_at)'
    );
    foreach (EconomicCalendar::events() as $ev) {
        $stmt->execute([
            'title'        => $ev['title'],
            'currency'     => strtoupper($ev['currency']),
            'impact'       => $ev['impact'] ?? 'high',
            'scheduled_at' => $ev['scheduled_at'],
        ]);
    }
    echo 'Migration 011: calendar_events seeded from static config.' . PHP_EOL;
} else {
    echo 'Migration 011: calendar_events already populated — skipped seeding.' . PHP_EOL;
}
