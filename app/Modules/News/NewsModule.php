<?php
declare(strict_types=1);
namespace App\Modules\News;

use App\Modules\Contracts\ModuleInterface;

final class NewsModule implements ModuleInterface
{
    public function name(): string { return 'news'; }

    /** No dedicated route today — news is consumed inside the scan cycle and the dashboard. */
    public function routes(): array { return []; }

    public function services(): array
    {
        return [
            \App\Services\NewsService::class, \App\Services\SentimentService::class,
            \App\Services\News\MarketauxNewsProvider::class, \App\Services\News\RssNewsProvider::class,
            \App\Services\News\NewsApiProvider::class,
        ];
    }

    public function config(): array
    {
        return ['NEWS_PROVIDER', 'MARKETAUX_API_KEY', 'MARKETAUX_MAX_ARTICLES', 'MARKETAUX_SCAN_MINUTES', 'USE_RSS_FALLBACK', 'RSS_MAX_ARTICLES', 'NEWS_API_KEY'];
    }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
