<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\News\MarketauxNewsProvider;
use App\Services\News\NewsApiProvider;
use App\Services\News\RssNewsProvider;

/**
 * Aggregates news from multiple providers with in-process caching.
 *
 * Priority: Marketaux → RSS fallback → NewsAPI fallback
 * Cache:    storage/cache/news_cache.json (TTL = MARKETAUX_SCAN_MINUTES)
 */
final class NewsService
{
    private const CACHE_FILE    = 'news_cache.json';
    private const MIN_THRESHOLD = 3;  // Minimum articles before activating next provider

    public function fetchHeadlines(array $markets = []): array
    {
        // ── 1. Return from cache if still fresh ──────────────────────────────
        $cached = $this->loadCache();
        if ($cached !== null) {
            return $cached;
        }

        // ── 2. Try Marketaux (primary) ───────────────────────────────────────
        $articles = [];
        $seenTitles = [];

        $marketaux = new MarketauxNewsProvider();
        $primary   = $marketaux->fetchHeadlines($markets);
        $articles  = $this->merge($articles, $primary, $seenTitles);

        // ── 3. RSS fallback if primary returned too few ──────────────────────
        if (count($articles) < self::MIN_THRESHOLD && (bool) env('USE_RSS_FALLBACK', true)) {
            $rss      = new RssNewsProvider();
            $rssFeed  = $rss->fetchHeadlines($markets);
            $articles = $this->merge($articles, $rssFeed, $seenTitles);
        }

        // ── 4. NewsAPI fallback (last resort) ────────────────────────────────
        $newsApiKey = trim((string) env('NEWS_API_KEY', ''));
        if (count($articles) < self::MIN_THRESHOLD && $newsApiKey !== '') {
            $newsApi  = new NewsApiProvider();
            $naFeed   = $newsApi->fetchHeadlines($markets);
            $articles = $this->merge($articles, $naFeed, $seenTitles);
        }

        // ── 5. Sort by published_at descending ───────────────────────────────
        usort($articles, static function (array $a, array $b): int {
            return strcmp($b['published_at'], $a['published_at']);
        });

        // ── 6. Cap total articles ────────────────────────────────────────────
        $maxArticles = max(1, (int) env('MARKETAUX_MAX_ARTICLES', 6) + (int) env('RSS_MAX_ARTICLES', 8));
        $articles    = array_slice($articles, 0, $maxArticles);

        // ── 7. Write cache ───────────────────────────────────────────────────
        $this->writeCache($articles);

        return $articles;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Merges $new into $existing, deduplicating by normalized title in-place.
     *
     * @param array<int, array<string, mixed>>  $existing
     * @param array<int, array<string, mixed>>  $new
     * @param array<string, true>               $seen  Pass by ref — shared across providers
     */
    private function merge(array $existing, array $new, array &$seen): array
    {
        foreach ($new as $item) {
            $key = mb_strtolower(trim((string) ($item['title'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $existing[] = $item;
        }
        return $existing;
    }

    // ── Cache helpers ─────────────────────────────────────────────────────────

    private function cacheFilePath(): string
    {
        $dir = base_path('storage/cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    /** Returns cached articles if cache is fresh, null if expired or missing. */
    private function loadCache(): ?array
    {
        $path = $this->cacheFilePath();
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['fetched_at']) || !isset($data['articles'])) {
            return null;
        }

        $ttlSeconds = max(60, (int) env('MARKETAUX_SCAN_MINUTES', 30) * 60);
        if ((time() - (int) $data['fetched_at']) > $ttlSeconds) {
            return null;
        }

        return is_array($data['articles']) ? $data['articles'] : null;
    }

    private function writeCache(array $articles): void
    {
        $path    = $this->cacheFilePath();
        $payload = json_encode([
            'fetched_at' => time(),
            'articles'   => $articles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($payload !== false) {
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }
}
