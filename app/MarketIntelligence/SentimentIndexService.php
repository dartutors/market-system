<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use App\Config\MarketIntelligence as MiConfig;
use App\Models\AiSummary;

/**
 * Converts the existing AiSummaryService/AiSummary output (a categorical
 * market_bias + a confidence float — see App\Services\AiSummaryService,
 * VALID_BIASES) into a single signed -100..+100 numeric index. No persisted
 * numeric sentiment aggregate existed before this — MarketScanService
 * computes a similar transient float per scan run but discards it (never
 * written to any table), and market_bias itself is categorical, not an index.
 */
final class SentimentIndexService
{
    private const BULLISH_BIASES = ['bullish_usd', 'risk_on'];
    private const BEARISH_BIASES = ['bearish_usd', 'risk_off'];

    /**
     * @return array{index:float, label:string, bias:string, confidence:float, based_on:string}
     */
    public function calculate(): array
    {
        $latest = AiSummary::latest(1)[0] ?? null;

        if ($latest === null) {
            return [
                'index'      => 0.0,
                'label'      => 'No data yet',
                'bias'       => 'neutral',
                'confidence' => 0.0,
                'based_on'   => 'No AI market summary has been generated yet.',
            ];
        }

        $bias       = (string) $latest['market_bias'];
        $confidence = (float) $latest['confidence'];

        $sign = match (true) {
            \in_array($bias, self::BULLISH_BIASES, true) => 1.0,
            \in_array($bias, self::BEARISH_BIASES, true) => -1.0,
            default                                        => 0.0,
        };

        // risk_on/risk_off are a broader macro read than a specific bullish_usd/
        // bearish_usd call, so they carry proportionally less weight in the index.
        $softening = \in_array($bias, ['risk_on', 'risk_off'], true) ? MiConfig::sentimentRiskSoftening() : 1.0;

        $index = round(max(-100.0, min(100.0, $sign * $confidence * $softening)), 2);

        return [
            'index'      => $index,
            'label'      => self::labelFor($index),
            'bias'       => $bias,
            'confidence' => $confidence,
            'based_on'   => "Latest AI market summary ({$latest['created_at']}).",
        ];
    }

    private static function labelFor(float $index): string
    {
        return match (true) {
            $index >= 40.0  => 'Strongly bullish',
            $index >= 10.0  => 'Mildly bullish',
            $index <= -40.0 => 'Strongly bearish',
            $index <= -10.0 => 'Mildly bearish',
            default          => 'Neutral / mixed',
        };
    }
}
