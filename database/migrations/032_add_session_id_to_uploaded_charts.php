<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Additive only. Nullable so every existing row, and TradeJournalController's
// own unrelated UploadedChart::create() call (which has no session concept),
// stay valid with session_id = NULL. Logical FK to vision_sessions.id — no
// real FOREIGN KEY constraint, matching this codebase's image_id convention.
$pdo->exec("
    ALTER TABLE uploaded_charts
    ADD COLUMN IF NOT EXISTS session_id INT UNSIGNED NULL AFTER id
");

$indexExists = $pdo->query("SHOW INDEX FROM uploaded_charts WHERE Key_name = 'idx_uploaded_charts_session'")->fetch();
if (!$indexExists) {
    $pdo->exec("ALTER TABLE uploaded_charts ADD INDEX idx_uploaded_charts_session (session_id)");
}

echo 'Migration 032: uploaded_charts widened with session_id.' . PHP_EOL;
