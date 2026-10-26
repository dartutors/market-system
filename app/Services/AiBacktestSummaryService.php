<?php
declare(strict_types=1);
namespace App\Services;

use App\AI\AIManager;
use App\AI\PromptLoader;
use App\Config\AI as AIConfig;

/**
 * Generates an educational AI summary of backtest results.
 *
 * Decision output:
 *   WAIT    — fewer than 10 signals: statistically meaningless.
 *   IMPROVE — data exists but win rate or sample size falls short.
 *   VALID   — ≥30 signals AND ≥60% win rate: strategy shows reliability.
 *
 * One AI call per backtest run (or rule-based fallback if AI unavailable).
 * Output is informational / educational only — never financial advice.
 */
final class AiBacktestSummaryService
{
    private const MIN_SIGNALS_WAIT  = 10;
    private const MIN_SIGNALS_VALID = 30;
    private const MIN_WIN_RATE_VALID     = 60.0;
    private const MIN_WIN_RATE_IMPROVE   = 55.0;
    private const OVERCONF_THRESHOLD     = 18.0;   // avg_confidence − win_rate > this = red flag
    private const VALID_DECISIONS = ['WAIT', 'IMPROVE', 'VALID'];

    // System/user prompt text now lives in resources/prompts/backtest_summary.md
    // (loaded via App\AI\PromptLoader) — see Phase 7 of the foundation refactor.

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * @param  array $stats  Output from BacktestService::run()
     * @return array{decision:string, confidence:int, summary:string, reasons:array, provider:string}
     */
    public function generate(array $stats): array
    {
        // Try AI-enhanced summary if enabled
        $result = null;
        if ((bool) env('USE_AI_SUMMARY', false)) {
            $result = $this->callAi($stats);
        }

        // Rule-based fallback (always available)
        return $result ?? $this->ruleBasedDecision($stats);
    }

    // ── Rule-based decision ───────────────────────────────────────────────────

    private function ruleBasedDecision(array $stats): array
    {
        $total    = (int)   ($stats['total_signals']  ?? 0);
        $winRate  = (float) ($stats['win_rate']        ?? 0.0);
        $avgConf  = (float) ($stats['avg_confidence']  ?? 50.0);
        $drawdown = (float) ($stats['max_drawdown_r']  ?? 0.0);
        $buyWR    = (float) ($stats['buy_win_rate']    ?? 0.0);
        $sellWR   = (float) ($stats['sell_win_rate']   ?? 0.0);

        $overConfident = ($avgConf - $winRate) > self::OVERCONF_THRESHOLD;

        // ── WAIT: insufficient data ───────────────────────────────────────────
        if ($total < self::MIN_SIGNALS_WAIT) {
            $reasons = [
                "Only {$total} signal(s) simulated — need at least " . self::MIN_SIGNALS_WAIT . " for basic analysis.",
                'This sample size is statistically meaningless.',
                'Extend the backtest to 60–90 days to collect more data.',
            ];
            return $this->build('WAIT', 30, $stats, $reasons,
                "Your backtest produced only {$total} signal(s) — far too few to draw conclusions. "
                . "Run the test over a longer period (60–90 days) before interpreting results.");
        }

        // ── VALID: statistically meaningful + strong performance ──────────────
        if ($total >= self::MIN_SIGNALS_VALID && $winRate >= self::MIN_WIN_RATE_VALID && !$overConfident) {
            $reasons = [
                "Win rate of {$winRate}% meets the ≥" . self::MIN_WIN_RATE_VALID . "% reliability target.",
                "{$total} signals provides a statistically meaningful sample.",
            ];
            if ($drawdown <= 3.0) {
                $reasons[] = "Drawdown of {$drawdown}R is within an acceptable range.";
            } else {
                $reasons[] = "Drawdown of {$drawdown}R is elevated — review position sizing.";
            }
            if ($buyWR >= 55 && $sellWR >= 55) {
                $reasons[] = 'Both BUY and SELL signals are performing consistently.';
            } elseif ($buyWR > $sellWR + 15) {
                $reasons[] = "BUY signals ({$buyWR}%) outperform SELL signals ({$sellWR}%) — consider bias filtering.";
            }
            $conf = min(88, (int) (($winRate / 100 * 60) + ($total / 100 * 20) + 10));
            return $this->build('VALID', $conf, $stats, $reasons,
                "With {$total} signals and a {$winRate}% win rate, this strategy shows signs of statistical "
                . "reliability. Results are educational only — past simulation performance does not "
                . "guarantee future results. Always apply strict risk management.");
        }

        // ── IMPROVE: data exists but not strong enough ────────────────────────
        $reasons = [];

        if ($winRate < self::MIN_WIN_RATE_IMPROVE) {
            $reasons[] = "Win rate of {$winRate}% is below the {" . self::MIN_WIN_RATE_IMPROVE . "}% minimum target.";
            if ($winRate < 45) {
                $reasons[] = 'Performance is worse than random — review entry conditions entirely.';
            } else {
                $reasons[] = 'Marginal performance — small filter improvements may help significantly.';
            }
        } elseif ($total < self::MIN_SIGNALS_VALID) {
            $reasons[] = "{$total} signals is not enough for statistical validation (need ≥" . self::MIN_SIGNALS_VALID . ").";
            $reasons[] = "Win rate of {$winRate}% is promising — extend the test period to confirm.";
        } else {
            $reasons[] = "Win rate of {$winRate}% falls short of the ≥" . self::MIN_WIN_RATE_VALID . "% validation target.";
        }

        if ($overConfident) {
            $gap = round($avgConf - $winRate, 1);
            $reasons[] = "Overconfidence detected: avg signal confidence ({$avgConf}%) exceeds win rate ({$winRate}%) by {$gap} points.";
            $reasons[] = 'The indicator scoring may inflate signals — consider tightening thresholds.';
        }

        if ($drawdown > 4.0) {
            $reasons[] = "Drawdown of {$drawdown}R is high — reduce position sizing or add a drawdown filter.";
        }

        $reasons[] = 'Refine entry conditions and collect more data before trusting this strategy.';

        $conf = max(25, min(55, (int) (($winRate / self::MIN_WIN_RATE_VALID) * 50)));
        return $this->build('IMPROVE', $conf, $stats, $reasons,
            "Results are inconsistent or the sample is insufficient. "
            . "Win rate of {$winRate}% over {$total} signals does not yet meet the reliability standard. "
            . "Review your filters, collect more backtest data, and paper-trade before drawing conclusions.");
    }

    // ── AI call ───────────────────────────────────────────────────────────────

    private function callAi(array $stats): ?array
    {
        // Previously: single-provider selection with no cross-provider
        // fallback — preserved as a "quirk" through an earlier refactor.
        // Phase 20 certification: switched to AIManager::complete() so a
        // transient primary-provider failure tries the configured fallback
        // before dropping to the rule-based summary, matching every other
        // AI service in this app.
        if (AIConfig::claudeApiKey() === '' && AIConfig::openAiApiKey() === '') {
            return null;
        }

        $prompt   = $this->buildPrompt($stats);
        $response = (new AIManager())->complete(
            $prompt['system'],
            $prompt['user'],
            ['service' => 'ai_backtest_summary', 'temperature' => 0.1]
        );

        if ($response === null) {
            return null;
        }

        return $this->parseAiResponse($response->text, $response->provider, $stats);
    }

    /**
     * @return array{system:string, user:string}
     */
    private function buildPrompt(array $stats): array
    {
        $total    = (int)   ($stats['total_signals']  ?? 0);
        $winRate  = (float) ($stats['win_rate']        ?? 0.0);
        $avgConf  = (float) ($stats['avg_confidence']  ?? 50.0);
        $drawdown = (float) ($stats['max_drawdown_r']  ?? 0.0);
        $buyWR    = (float) ($stats['buy_win_rate']    ?? 0.0);
        $sellWR   = (float) ($stats['sell_win_rate']   ?? 0.0);

        return PromptLoader::load('backtest_summary', [
            'total'          => $total,
            'win_rate'       => $winRate,
            'avg_confidence' => $avgConf,
            'drawdown'       => $drawdown,
            'buy_win_rate'   => $buyWR,
            'sell_win_rate'  => $sellWR,
        ]);
    }

    private function parseAiResponse(string $text, string $provider, array $stats): ?array
    {
        $text = trim((string) preg_replace('/^```(?:json)?\s*/i', '', $text));
        $text = trim((string) preg_replace('/\s*```\s*$/i', '', $text));

        $data = json_decode($text, true);
        if (!\is_array($data)) {
            error_log("[AiBacktestSummaryService] {$provider} returned invalid JSON.");
            return null;
        }

        $decision = strtoupper(trim((string) ($data['decision'] ?? 'WAIT')));
        if (!\in_array($decision, self::VALID_DECISIONS, true)) {
            $decision = 'WAIT';
        }

        $confidence = max(0, min(100, (int) ($data['confidence'] ?? 50)));
        $summary    = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            return null;
        }

        $reasons = [];
        if (isset($data['reasons']) && \is_array($data['reasons'])) {
            foreach ($data['reasons'] as $r) {
                $reasons[] = trim((string) $r);
            }
        }

        return $this->build($decision, $confidence, $stats, $reasons, $summary, $provider);
    }

    // ── Builder ───────────────────────────────────────────────────────────────

    private function build(
        string $decision,
        int    $confidence,
        array  $stats,
        array  $reasons,
        string $summary,
        string $provider = 'rule_based'
    ): array {
        // Reliability flag: independent of AI / rule-based decision
        $total   = (int)   ($stats['total_signals'] ?? 0);
        $winRate = (float) ($stats['win_rate']       ?? 0.0);
        $reliable = $total >= self::MIN_SIGNALS_VALID && $winRate >= self::MIN_WIN_RATE_VALID;

        return [
            'decision'   => $decision,
            'confidence' => max(0, min(100, $confidence)),
            'summary'    => $summary,
            'reasons'    => array_values(array_slice($reasons, 0, 5)),
            'provider'   => $provider,
            'reliable'   => $reliable,
        ];
    }
}
