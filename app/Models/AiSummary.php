<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class AiSummary
{
    /**
     * Returns a cached summary for the given headline hash if it was created
     * within the last $minutes minutes, or null if no fresh record exists.
     */
    public static function findRecentByHash(string $headlineHash, int $minutes): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_summaries
             WHERE headline_hash = :hash
               AND created_at   >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->bindValue(':hash',    $headlineHash);
        $stmt->bindValue(':minutes', max(1, $minutes), PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return self::decode($row);
    }

    /**
     * Persists a new AI summary record.
     * Accepts optional pair_notes (array — stored as JSON) and risk_note.
     */
    public static function create(array $data): int
    {
        $pairNotes = isset($data['pair_notes']) && \is_array($data['pair_notes'])
                     ? json_encode($data['pair_notes'], JSON_UNESCAPED_UNICODE)
                     : null;

        $stmt = Database::connection()->prepare(
            'INSERT INTO ai_summaries
                (provider, model, market_scope, summary_text, market_bias,
                 confidence, headline_hash, pair_notes, risk_note, created_at)
             VALUES
                (:provider, :model, :market_scope, :summary_text, :market_bias,
                 :confidence, :headline_hash, :pair_notes, :risk_note, NOW())'
        );

        $stmt->execute([
            'provider'      => (string)  ($data['provider']      ?? 'rule_based'),
            'model'         => (string)  ($data['model']         ?? ''),
            'market_scope'  => (string)  ($data['market_scope']  ?? 'ALL'),
            'summary_text'  => (string)  ($data['summary_text']  ?? ''),
            'market_bias'   => (string)  ($data['market_bias']   ?? 'mixed'),
            'confidence'    => (float)   ($data['confidence']    ?? 0.0),
            'headline_hash' => (string)  ($data['headline_hash'] ?? ''),
            'pair_notes'    => $pairNotes,
            'risk_note'     => (string)  ($data['risk_note']     ?? ''),
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** Returns the $limit most recent AI summaries, newest first. */
    public static function latest(int $limit = 5): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_summaries ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'decode'], $rows ?: []);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Decodes JSON columns in a raw DB row. */
    private static function decode(array $row): array
    {
        if (isset($row['pair_notes']) && \is_string($row['pair_notes'])) {
            $decoded = json_decode($row['pair_notes'], true);
            $row['pair_notes'] = \is_array($decoded) ? $decoded : [];
        } else {
            $row['pair_notes'] = [];
        }

        $row['confidence'] = (float) ($row['confidence'] ?? 0.0);
        return $row;
    }
}
