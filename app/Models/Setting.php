<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

final class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row === false ? $default : $row['setting_value'];
    }

    public static function set(string $key, ?string $value): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    public static function updatedAt(string $key): ?string
    {
        $stmt = Database::connection()->prepare('SELECT updated_at FROM settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row === false ? null : $row['updated_at'];
    }

    /** @return array<string, string> */
    public static function allWithPrefix(string $prefix): array
    {
        $stmt = Database::connection()->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE :prefix');
        $stmt->execute(['prefix' => $prefix . '%']);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }
}
