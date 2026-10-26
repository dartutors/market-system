<?php
declare(strict_types=1);
namespace App\Services;

final class SentimentService
{
    /**
     * Analyses a headline and returns polarity, impact, and market bias.
     *
     * Scoring layers (cumulative):
     *   ±2  Institutional hawkish / dovish phrases (high-weight)
     *   ±1  General directional words
     *   +15 per impact booster word added to the impact score
     *
     * @return array{polarity: float, impact: float, bias: string}
     */
    public function analyze(string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            return ['polarity' => 0.0, 'impact' => 0.0, 'bias' => 'neutral'];
        }

        $lower = mb_strtolower($title);
        $score = 0;

        // ── Layer 1: High-weight institutional signals (±2) ──────────────────
        // Hawkish  → rate hike expectation → bullish for the relevant currency
        // Dovish   → rate cut expectation  → bearish for the relevant currency
        $hawkish = [
            'hawkish', 'rate hike', 'rate increase', 'tightening cycle',
            'raises rates', 'hikes rates', 'above expectations', 'beats forecast',
            'hot inflation', 'higher inflation', 'strong cpi', 'strong nfp',
            'strong jobs', 'strong payrolls', 'robust growth', 'aggressive tightening',
            'above estimate', 'surprise hike', 'faster than expected',
        ];

        $dovish = [
            'dovish', 'rate cut', 'rate decrease', 'easing cycle',
            'cuts rates', 'holds rates at zero', 'pause rate', 'policy pivot', 'pivot',
            'below expectations', 'misses forecast', 'cooling inflation', 'lower inflation',
            'weak cpi', 'weak nfp', 'weak jobs', 'weak payrolls', 'below estimate',
            'recession fears', 'growth slowdown', 'surprise cut', 'slower than expected',
            'labor market softening',
        ];

        foreach ($hawkish as $phrase) {
            if (str_contains($lower, $phrase)) {
                $score += 2;
            }
        }

        foreach ($dovish as $phrase) {
            if (str_contains($lower, $phrase)) {
                $score -= 2;
            }
        }

        // ── Layer 2: General directional terms (±1) ──────────────────────────
        $positive = [
            'rise', 'rises', 'growth', 'strong', 'beat', 'bullish', 'gain',
            'recovery', 'surge', 'higher', 'upside', 'accelerate', 'robust',
            'outperform', 'rally', 'advance', 'strengthen', 'rebound', 'soar',
        ];

        $negative = [
            'fall', 'falls', 'drop', 'weak', 'miss', 'bearish', 'loss',
            'cut', 'recession', 'lower', 'downside', 'slowdown', 'contraction',
            'decline', 'retreat', 'soften', 'disappoint', 'plunge', 'slump',
        ];

        foreach ($positive as $word) {
            if (str_contains($lower, $word)) {
                $score++;
            }
        }

        foreach ($negative as $word) {
            if (str_contains($lower, $word)) {
                $score--;
            }
        }

        // ── Layer 3: Impact boosters (central bank / key economic events) ─────
        // Each matched word adds to the impact score regardless of polarity.
        $impactWords = [
            'fed', 'fomc', 'federal reserve',
            'ecb', 'european central bank',
            'boe', 'bank of england',
            'boj', 'bank of japan',
            'inflation', 'cpi', 'pce', 'core inflation',
            'interest rate', 'rate decision', 'rate hike', 'rate cut',
            'payrolls', 'nfp', 'non-farm', 'unemployment', 'jobless',
            'gdp', 'pmi', 'manufacturing pmi', 'services pmi',
            'monetary policy', 'central bank', 'quantitative easing', 'tapering',
            'balance sheet', 'yield curve',
        ];

        $impact = min(abs($score) * 15, 70);
        foreach ($impactWords as $word) {
            if (str_contains($lower, $word)) {
                $impact += 15;
            }
        }
        $impact = min($impact, 100.0);

        $bias = $score > 0 ? 'bullish' : ($score < 0 ? 'bearish' : 'neutral');

        return [
            'polarity' => (float) $score,
            'impact'   => (float) $impact,
            'bias'     => $bias,
        ];
    }
}
