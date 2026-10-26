<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class UploadedChart
{
    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO uploaded_charts
                    (session_id, market_label, timeframe, file_path, mime_type, width, height,
                     source, uploaded_by, created_at)
                 VALUES
                    (:session_id, :market_label, :timeframe, :file_path, :mime_type, :width, :height,
                     :source, :uploaded_by, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'session_id'   => $data['session_id']   ?? null,
            'market_label' => $data['market_label'] ?? '',
            'timeframe'    => $data['timeframe']     ?? '',
            'file_path'    => $data['file_path']     ?? '',
            'mime_type'    => $data['mime_type']     ?? '',
            'width'        => $data['width']         ?? 0,
            'height'       => $data['height']        ?? 0,
            'source'       => $data['source']        ?? 'manual_upload',
            'uploaded_by'  => $data['uploaded_by']    ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public static function findBySessionId(int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM uploaded_charts WHERE session_id = :session_id AND deleted_at IS NULL ORDER BY id ASC'
        );
        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM uploaded_charts WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    public static function latest(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM uploaded_charts WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function softDelete(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE uploaded_charts SET deleted_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Tags a batch of already-created rows with a vision_sessions id after
     * the fact — the session row can only be created once every slot's
     * combined result is known, but each slot's uploaded_charts row is
     * created earlier, per-slot, during analysis.
     *
     * @param array<int, int> $imageIds
     */
    public static function attachToSession(array $imageIds, int $sessionId): void
    {
        $imageIds = array_values(array_unique(array_map('intval', $imageIds)));
        if (empty($imageIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, \count($imageIds), '?'));
        $stmt = Database::connection()->prepare(
            "UPDATE uploaded_charts SET session_id = ? WHERE id IN ({$placeholders})"
        );
        $stmt->execute([$sessionId, ...$imageIds]);
    }
}
