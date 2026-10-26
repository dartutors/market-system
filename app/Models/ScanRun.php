<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

final class ScanRun {
    public static function create(string $symbol, string $status, string $details): void {
        $stmt = Database::connection()->prepare('INSERT INTO scan_runs (market_symbol, run_status, details, created_at) VALUES (:market_symbol, :run_status, :details, NOW())');
        $stmt->execute([
            'market_symbol' => $symbol,
            'run_status' => $status,
            'details' => $details,
        ]);
    }
}
