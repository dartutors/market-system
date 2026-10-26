<?php
declare(strict_types=1);

namespace App\Services\News;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class NewsApiProvider implements NewsProviderInterface
{
    private const ENDPOINT = 'https://newsapi.org/v2/everything';

    private const REQUIRED_TERMS = [
        'eur/usd', 'eurusd', 'gbp/usd', 'gbpusd', 'usd/jpy', 'usdjpy',
        'euro', 'pound', 'sterling', 'yen', 'dollar', 'forex', 'foreign exchange',
        'fed', 'federal reserve', 'fomc', 'ecb', 'boe', 'boj',
        'inflation', 'cpi', 'interest rate', 'rate decision', 'monetary policy',
        'nfp', 'non-farm', 'payrolls', 'gdp', 'pmi', 'hawkish', 'dovish',
    ];

    private const BLOCKED_TERMS = [
        'sport', 'football', 'soccer', 'tennis', 'basketball',
        'celebrity', 'movie', 'music', 'entertainment', 'flooring',
        'automotive', 'lifestyle', 'fashion', 'gaming', 'cargo',
        'drilling rig', 'work-life', 'nfl', 'nba',
    ];

    public function fetchHeadlines(array $markets = []): array
    {
        $apiKey = trim((string) env('NEWS_API_KEY', ''));
        if ($apiKey === '') {
            return [];
        }

        $limit = max(1, min(25, (int) env('NEWS_MAX_ARTICLES', 10)));

        $client = new Client([
            'timeout'         => 20,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'MarketSignalSystem/1.0',
            ],
        ]);

        try {
            $response = $client->get(self::ENDPOINT, [
                'query' => [
                    'q'        => $this->buildQuery(),
                    'language' => 'en',
                    'sortBy'   => 'publishedAt',
                    'pageSize' => $limit,
                    'searchIn' => 'title,description',
                    'apiKey'   => $apiKey,
                ],
            ]);
        } catch (GuzzleException $e) {
            error_log('[NewsApiProvider] Request failed: ' . $e->getMessage());
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload) || ($payload['status'] ?? '') !== 'ok') {
            return [];
        }

        $items      = [];
        $seenTitles = [];

        foreach (($payload['articles'] ?? []) as $article) {
            if (!is_array($article)) {
                continue;
            }

            $title = $this->cleanText((string) ($article['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $desc = $this->cleanText((string) ($article['description'] ?? ''));
            if (!$this->isRelevant($title, $desc)) {
                continue;
            }

            $normalized = mb_strtolower($title);
            if (isset($seenTitles[$normalized])) {
                continue;
            }
            $seenTitles[$normalized] = true;

            $items[] = [
                'title'        => $title,
                'source'       => $this->cleanText((string) ($article['source']['name'] ?? 'NewsAPI')),
                'url'          => $this->sanitizeUrl((string) ($article['url'] ?? '')),
                'published_at' => $this->normalizeDate((string) ($article['publishedAt'] ?? '')),
                'provider'     => 'newsapi',
                'symbols'      => $this->guessSymbols($title . ' ' . $desc),
            ];
        }

        return $items;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildQuery(): string
    {
        return '("EUR/USD" OR EURUSD OR "GBP/USD" OR GBPUSD OR "USD/JPY" OR USDJPY '
             . 'OR forex OR ECB OR Fed OR "Federal Reserve" OR BOE OR BOJ '
             . 'OR inflation OR CPI OR "interest rates" OR "monetary policy")';
    }

    private function isRelevant(string $title, string $description): bool
    {
        $haystack = mb_strtolower($title . ' ' . $description);

        $matched = false;
        foreach (self::REQUIRED_TERMS as $term) {
            if (str_contains($haystack, $term)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return false;
        }

        foreach (self::BLOCKED_TERMS as $term) {
            if (str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    private function guessSymbols(string $text): array
    {
        $text    = mb_strtolower($text);
        $symbols = [];
        if (str_contains($text, 'eur') || str_contains($text, 'ecb') || str_contains($text, 'euro')) {
            $symbols[] = 'EUR/USD';
        }
        if (str_contains($text, 'gbp') || str_contains($text, 'boe') || str_contains($text, 'pound') || str_contains($text, 'sterling')) {
            $symbols[] = 'GBP/USD';
        }
        if (str_contains($text, 'jpy') || str_contains($text, 'boj') || str_contains($text, 'yen')) {
            $symbols[] = 'USD/JPY';
        }
        return $symbols;
    }

    private function cleanText(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        return ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) ? $url : '';
    }

    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '';
        }
    }
}
