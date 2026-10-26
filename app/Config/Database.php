<?php
declare(strict_types=1);
namespace App\Config;

final class Database {
    public static function dsn(): string {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            (string) env('DB_HOST', '127.0.0.1'),
            (string) env('DB_PORT', '3306'),
            (string) env('DB_DATABASE', 'market_signal'),
            (string) env('DB_CHARSET', 'utf8mb4')
        );
    }
}
