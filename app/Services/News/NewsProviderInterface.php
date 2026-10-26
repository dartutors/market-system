<?php
declare(strict_types=1);

namespace App\Services\News;

interface NewsProviderInterface
{
    /**
     * Fetch normalized headlines.
     *
     * @param  array<string> $markets  Optional list of market labels to bias results (e.g. ['EUR/USD'])
     * @return array<int, array{title:string, source:string, url:string, published_at:string, provider:string, symbols:array<string>}>
     */
    public function fetchHeadlines(array $markets = []): array;
}
