<?php
declare(strict_types=1);

namespace App\Services\News;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class MarketauxNewsProvider implements NewsProviderInterface
{
    private const ENDPOINT = 'https://api.marketaux.com/v1/news/all';

    private const BLOCKED_TERMS = [
        'sport', 'football', 'soccer', 'tennis', 'basketball', 'celebrity',
        'movie', 'music', 'entertainment', 'flooring', 'automotive', 'car review',
        'lifestyle', 'fashion', 'gaming', 'nfl', 'nba', 'mlb', 'nhl',
    ];

    private const REQUIRED_TERMS = [
        'eur/usd', 'eurusd', 'gbp/usd', 'gbpusd', 'usd/jpy', 'usdjpy',
        'euro', 'pound', 'sterling', 'yen', 'dollar', 'forex', 'foreign exchange',
        'fed', 'federal reserve', 'fomc', 'ecb', 'european central bank',
        'boe', 'bank of england', 'boj', 'bank of japan',
        'inflation', 'cpi', 'interest rate', 'rate decision', 'monetary policy',
        'nfp', 'non-farm', 'payrolls', 'gdp', 'pmi', 'hawkish', 'dovish',
    ];

    public function fetchHeadlines(array $markets = []): array
    {
        $apiKey = trim((string) env('MARKETAUX_API_KEY', ''));
        if ($apiKey === '') {
            return [];
        }

        $limit = max(1, min(25, (int) env('MARKETAUX_MAX_ARTICLES', 6)));

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
                    'api_token' => $apiKey,
                    'language'  => 'en',
                    'limit'     => $limit,
                    'sort'      => 'published_on',
                    'search'    => $this->buildSearchQuery(),
                ],
            ]);
        } catch (GuzzleException $e) {
            error_log('[MarketauxNewsProvider] Request failed: ' . $e->getMessage());
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            error_log('[MarketauxNewsProvider] HTTP ' . $response->getStatusCode());
            return [];
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            error_log('[MarketauxNewsProvider] Unexpected response structure.');
            return [];
        }

        $items      = [];
        $seenTitles = [];

        foreach ($payload['data'] as $article) {
            if (!is_array($article)) {
                continue;
            }

            $title = $this->cleanText((string) ($article['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $normalized = mb_strtolower($title);
            if (isset($seenTitles[$normalized])) {
                continue;
            }

            $desc = $this->cleanText((string) ($article['description'] ?? ''));
            if (!$this->isRelevant($title, $desc)) {
                continue;
            }

            $seenTitles[$normalized] = true;

            $items[] = [
                'title'        => $title,
                'source'       => $this->cleanText((string) ($article['source'] ?? 'Marketaux')),
                'url'          => $this->sanitizeUrl((string) ($article['url'] ?? '')),
                'published_at' => $this->normalizeDate((string) ($article['published_at'] ?? '')),
                'provider'     => 'marketaux',
                'symbols'      => $this->extractSymbols($article['entities'] ?? []),
            ];
        }

        return $items;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildSearchQuery(): string
    {
        return 'EURUSD OR GBPUSD OR USDJPY OR Fed OR ECB OR BOE OR BOJ OR '
             . '"interest rate" OR "rate decision" OR inflation OR CPI OR forex OR "monetary policy"';
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

    private function extractSymbols(mixed $entities): array
    {
        if (!is_array($entities)) {
            return [];
        }

        $map = ['EUR' => 'EUR/USD', 'GBP' => 'GBP/USD', 'JPY' => 'USD/JPY', 'USD' => 'USD'];
        $out = [];
        foreach ($entities as $entity) {
            $sym = strtoupper((string) ($entity['symbol'] ?? ''));
            if (isset($map[$sym]) && !in_array($map[$sym], $out, true)) {
                $out[] = $map[$sym];
            }
        }
        return $out;
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
