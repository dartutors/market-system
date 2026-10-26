<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class WeightChangeProposal
{
    public static function create(array $data): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO weight_change_proposals
                (source_key, current_weight, proposed_weight, rationale, evidence_json, sample_size, status, created_at)
             VALUES
                (:source_key, :current_weight, :proposed_weight, :rationale, :evidence_json, :sample_size, \'pending\', NOW())'
        );
        $stmt->execute([
            'source_key'       => (string) $data['source_key'],
            'current_weight'   => (float) $data['current_weight'],
            'proposed_weight'  => (float) $data['proposed_weight'],
            'rationale'        => (string) $data['rationale'],
            'evidence_json'    => (string) $data['evidence_json'],
            'sample_size'      => (int) $data['sample_size'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function pending(): array
    {
        $stmt = Database::connection()->query("SELECT * FROM weight_change_proposals WHERE status = 'pending' ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function recent(int $limit = 30): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM weight_change_proposals ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM weight_change_proposals WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A pending proposal for this exact source, if one already exists — avoids piling up duplicate proposals every time the optimizer re-runs. */
    public static function pendingForSource(string $sourceKey): ?array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM weight_change_proposals WHERE source_key = :key AND status = 'pending' LIMIT 1");
        $stmt->execute(['key' => $sourceKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function decide(int $id, string $status, ?int $decidedBy): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE weight_change_proposals SET status = :status, decided_at = NOW(), decided_by = :decided_by WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'decided_by' => $decidedBy, 'id' => $id]);
    }
}
