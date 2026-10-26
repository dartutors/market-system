<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class EvidenceSourceWeight
{
    /** @return array<string, float> source_key => weight */
    public static function all(): array
    {
        $stmt = Database::connection()->query('SELECT source_key, weight FROM evidence_source_weights');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['source_key']] = (float) $row['weight'];
        }
        return $indexed;
    }

    /** Used only by an approved weight-change proposal — never called directly from evidence-gathering code. */
    public static function set(string $sourceKey, float $weight): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO evidence_source_weights (source_key, weight, updated_at)
             VALUES (:key, :weight, NOW())
             ON DUPLICATE KEY UPDATE weight = VALUES(weight), updated_at = NOW()'
        );
        $stmt->execute(['key' => $sourceKey, 'weight' => $weight]);
    }
}
