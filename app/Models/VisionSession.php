<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class VisionSession
{
    public static function create(array $data): int
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO vision_sessions
                (market_label, timeframes_uploaded_json, completeness_score, overall_trend,
                 decision, confidence, risk_level, synthesis_json, provider, created_by, created_at)
             VALUES
                (:market_label, :timeframes_uploaded_json, :completeness_score, :overall_trend,
                 :decision, :confidence, :risk_level, :synthesis_json, :provider, :created_by, NOW())'
        );
        $stmt->execute([
            'market_label'             => (string) ($data['market_label']             ?? ''),
            'timeframes_uploaded_json' => (string) ($data['timeframes_uploaded_json'] ?? '[]'),
            'completeness_score'       => (float)  ($data['completeness_score']       ?? 0.0),
            'overall_trend'            => (string) ($data['overall_trend']            ?? 'neutral'),
            'decision'                 => (string) ($data['decision']                 ?? 'NO_TRADE'),
            'confidence'               => (float)  ($data['confidence']               ?? 0.0),
            'risk_level'               => (string) ($data['risk_level']               ?? 'medium'),
            'synthesis_json'           => (string) ($data['synthesis_json']           ?? '{}'),
            'provider'                 => (string) ($data['provider']                 ?? 'rule_based'),
            'created_by'               => $data['created_by']                          ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM vision_sessions WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    public static function recent(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM vision_sessions ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /** Most recent vision session for a specific market within the last $hours, or null if none. Used by the Consensus Engine's Vision vote. */
    public static function latestForMarket(string $marketLabel, int $hours = 24): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM vision_sessions
             WHERE market_label = :label AND created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->bindValue(':label', $marketLabel);
        $stmt->bindValue(':hours', max(1, $hours), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }
}
