<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class NewsItem
{
    public static function existsByTitle(string $title): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM news_items WHERE title = :title LIMIT 1'
        );
        $stmt->execute(['title' => $title]);
        return (bool) $stmt->fetch();
    }

    /**
     * Insert a news item. INSERT IGNORE against uq_news_items_title
     * (migration 038) makes this safe against the cron and the "run scan
     * now" admin endpoint racing each other through existsByTitle() at the
     * same moment — the check-then-insert race is now backstopped at the
     * DB level instead of relying purely on the app-level check.
     * The `provider` key is optional for backward compatibility — defaults to 'unknown'.
     */
    public static function create(array $data): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO news_items
                (title, source, url, published_at, raw_sentiment, impact_score, market_bias, provider, created_at)
             VALUES
                (:title, :source, :url, :published_at, :raw_sentiment, :impact_score, :market_bias, :provider, NOW())'
        );

        $stmt->execute([
            'title'         => (string) ($data['title']         ?? ''),
            'source'        => (string) ($data['source']        ?? 'unknown'),
            'url'           => (string) ($data['url']           ?? ''),
            'published_at'  => isset($data['published_at']) && $data['published_at'] !== ''
                               ? (string) $data['published_at']
                               : null,
            'raw_sentiment' => (float)  ($data['raw_sentiment'] ?? 0.0),
            'impact_score'  => (float)  ($data['impact_score']  ?? 0.0),
            'market_bias'   => (string) ($data['market_bias']   ?? 'neutral'),
            'provider'      => (string) ($data['provider']      ?? 'unknown'),
        ]);
    }

    public static function latest(int $limit = 12): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM news_items ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM news_items WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    /** Persists an AI impact explanation so repeat clicks never re-bill the AI provider. */
    public static function saveExplanation(int $id, string $explanation, string $provider): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE news_items
             SET ai_explanation = :explanation, ai_explanation_provider = :provider, ai_explanation_generated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['explanation' => $explanation, 'provider' => $provider, 'id' => $id]);
    }
}
