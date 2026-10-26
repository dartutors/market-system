<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use App\Config\MarketIntelligence as MiConfig;
use App\Models\NewsItem;

/**
 * Surfaces the numeric news_items.impact_score (0-100, already computed per
 * article by App\Services\SentimentService::analyze() and persisted on
 * every scan) as a standalone aggregate — this data already existed but had
 * no dedicated "impact scoring" view anywhere before this class.
 */
final class NewsImpactScorer
{
    /**
     * @return array{average_impact:float, high_impact_count:int, recent:array}
     */
    public function calculate(): array
    {
        $items = NewsItem::latest(MiConfig::newsLookbackCount());

        if (empty($items)) {
            return ['average_impact' => 0.0, 'high_impact_count' => 0, 'recent' => []];
        }

        $threshold  = MiConfig::newsHighImpactThreshold();
        $sum        = 0.0;
        $highCount  = 0;
        $recent     = [];

        foreach ($items as $item) {
            $impact = (float) ($item['impact_score'] ?? 0.0);
            $sum   += $impact;
            if ($impact >= $threshold) {
                $highCount++;
            }

            $recent[] = [
                'title'        => (string) $item['title'],
                'source'       => (string) $item['source'],
                'impact_score' => round($impact, 1),
                'market_bias'  => (string) $item['market_bias'],
                'published_at' => $item['published_at'] ?? null,
            ];
        }

        return [
            'average_impact'    => round($sum / count($items), 2),
            'high_impact_count' => $highCount,
            'recent'            => $recent,
        ];
    }
}
