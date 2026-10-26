<?php
declare(strict_types=1);
namespace App\Services;

use App\AI\AIManager;
use App\AI\PromptLoader;
use App\Config\AI as AIConfig;

/**
 * Generates an educational explanation of a Phase 3.1 Advanced Backtest run
 * (strategy comparison + walk-forward + Monte Carlo + parameter
 * optimization combined). Same "rule-based always available, one optional
 * AI call" structure as App\Services\AiBacktestSummaryService, but the
 * decision rules additionally weigh walk-forward stability and the Monte
 * Carlo loss probability — not just the point-estimate win rate — since
 * those two exist specifically to catch an overfit-looking backtest that a
 * raw win rate alone would rate as VALID.
 *
 * @phpstan-type AdvancedBacktestResult array{
 *   primary: array{strategy:string, summary:array<string,mixed>},
 *   comparison: array<int, array{strategy:string, summary:array<string,mixed>, best:bool}>,
 *   walk_forward: array<string,mixed>,
 *   monte_carlo: array<string,mixed>,
 *   optimization: array<string,mixed>
 * }
 */
final class AiAdvancedBacktestExplanationService
{
    private const MIN_SIGNALS_WAIT     = 10;
    private const MIN_SIGNALS_VALID    = 30;
    private const MIN_WIN_RATE_VALID   = 60.0;
    private const MAX_LOSS_PROBABILITY = 35.0;
    private const VALID_DECISIONS      = ['WAIT', 'IMPROVE', 'VALID'];

    /**
     * @param array $result Combined output described in the class docblock.
     * @return array{decision:string, confidence:int, summary:string, reasons:array, provider:string, reliable:bool}
     */
    public function generate(array $result): array
    {
        $ai = null;
        if ((bool) env('USE_AI_SUMMARY', false)) {
            $ai = $this->callAi($result);
        }

        return $ai ?? $this->ruleBasedDecision($result);
    }

    // ── Rule-based decision ───────────────────────────────────────────────────

    private function ruleBasedDecision(array $result): array
    {
        $facts = $this->extractFacts($result);

        if ($facts['total_signals'] < self::MIN_SIGNALS_WAIT) {
            $reasons = [
                "Only {$facts['total_signals']} signal(s) simulated by the primary strategy — need at least " . self::MIN_SIGNALS_WAIT . ' for any analysis.',
                'Increase the bars-back window before trusting any of the comparison/walk-forward/optimization results below.',
            ];
            return $this->build('WAIT', 30, $reasons,
                "Only {$facts['total_signals']} signal(s) were produced — far too few for the walk-forward, Monte Carlo, "
                . 'or optimization results to mean anything yet. Extend the analysis window and re-run.');
        }

        if ($facts['stability'] === 'insufficient_data') {
            $reasons = [
                'Walk-forward testing could not run — not enough history for the requested number of windows.',
                'The win rate/profit factor shown above are in-sample only and have NOT been confirmed out-of-sample.',
                'Increase the bars-back window (or reduce the window count) to enable walk-forward validation.',
            ];
            return $this->build('IMPROVE', 35, $reasons,
                'Walk-forward testing could not run because there was not enough historical data for the requested '
                . 'number of windows. Without an out-of-sample check, the numbers above cannot be trusted as robust — '
                . 'increase the analysis window and re-run before drawing conclusions.');
        }

        if ($facts['stability'] === 'unstable') {
            $reasons = [
                "Walk-forward testing picked a different best strategy in most windows — dominant strategy: {$facts['dominant_strategy']}.",
                'An unstable walk-forward winner is a classic overfitting signal: what looked best in-sample did not hold out-of-sample.',
                "Primary strategy ({$facts['primary_strategy']}) win rate of {$facts['primary_win_rate']}% should not be trusted in isolation.",
            ];
            return $this->build('IMPROVE', 35, $reasons,
                'Walk-forward testing found no single strategy that consistently won across historical windows. '
                . 'This is a strong sign the good-looking numbers above are curve-fit to this particular history rather '
                . 'than a durable edge. Re-test with more data before trusting any of these results.');
        }

        if ($facts['loss_probability'] >= self::MAX_LOSS_PROBABILITY) {
            $reasons = [
                "Monte Carlo resampling gives a {$facts['loss_probability']}% chance of an overall losing outcome from this exact trade mix, just from reordering.",
                "The 5th-95th percentile final-equity range ({$facts['p5']}R to {$facts['p95']}R) is wide relative to the median ({$facts['p50']}R).",
                'A high loss probability means the reported win rate could easily have gone the other way by chance alone.',
            ];
            return $this->build('IMPROVE', 40, $reasons,
                "Monte Carlo simulation puts the probability of an overall losing outcome at {$facts['loss_probability']}%, "
                . 'even though the primary run itself showed a positive result. The sample is too sensitive to trade '
                . 'order/luck to trust yet — collect more signals before drawing conclusions.');
        }

        if ($facts['total_signals'] >= self::MIN_SIGNALS_VALID && $facts['primary_win_rate'] >= self::MIN_WIN_RATE_VALID) {
            $reasons = [
                "Primary strategy ({$facts['primary_strategy']}) win rate of {$facts['primary_win_rate']}% meets the ≥" . self::MIN_WIN_RATE_VALID . '% target.',
                "Walk-forward testing is stable (dominant strategy: {$facts['dominant_strategy']}) — the edge held out-of-sample, not just in-sample.",
                "Monte Carlo loss probability of {$facts['loss_probability']}% is within an acceptable range.",
            ];
            if ($facts['best_strategy'] !== $facts['primary_strategy'] && $facts['best_strategy_win_rate'] > $facts['primary_win_rate'] + 10) {
                $reasons[] = "Note: {$facts['best_strategy']} outperformed the primary strategy in direct comparison ({$facts['best_strategy_win_rate']}% vs {$facts['primary_win_rate']}%) — consider switching.";
            }
            $conf = min(88, (int) (($facts['primary_win_rate'] / 100 * 50) + ((100 - $facts['loss_probability']) / 100 * 25) + 13));
            return $this->build('VALID', $conf, $reasons,
                "With {$facts['total_signals']} signals, a {$facts['primary_win_rate']}% win rate that held up in walk-forward "
                . "testing, and a Monte Carlo loss probability of only {$facts['loss_probability']}%, this strategy shows real "
                . 'signs of statistical reliability. Educational only — past simulation performance never guarantees future '
                . 'results. Always apply strict risk management.');
        }

        $reasons = ["Primary strategy win rate of {$facts['primary_win_rate']}% falls short of the ≥" . self::MIN_WIN_RATE_VALID . '% validation target.'];
        if ($facts['best_strategy'] !== $facts['primary_strategy']) {
            $reasons[] = "{$facts['best_strategy']} performed better in direct comparison ({$facts['best_strategy_win_rate']}% win rate) — worth testing as the primary strategy instead.";
        }
        if ($facts['best_target_mult'] !== null && ($facts['best_target_mult'] !== 2.0 || $facts['best_stop_mult'] !== 1.2)) {
            $reasons[] = "Parameter optimization suggests a {$facts['best_target_mult']}x target / {$facts['best_stop_mult']}x stop combination outperforms the 2.0x/1.2x default.";
        }
        $reasons[] = 'Refine strategy/parameter selection and collect more data before trusting this configuration.';

        $conf = max(25, min(55, (int) (($facts['primary_win_rate'] / self::MIN_WIN_RATE_VALID) * 50)));
        return $this->build('IMPROVE', $conf, $reasons,
            "Results are inconsistent or the sample is insufficient. Win rate of {$facts['primary_win_rate']}% over "
            . "{$facts['total_signals']} signals does not yet meet the reliability standard. Review the strategy "
            . 'comparison and optimization results above, collect more data, and paper-trade before drawing conclusions.');
    }

    // ── AI call ───────────────────────────────────────────────────────────────

    private function callAi(array $result): ?array
    {
        // Phase 20 certification: switched from single-provider selection
        // (no cross-provider fallback) to AIManager::complete(), matching
        // every other AI service in this app — a transient primary-provider
        // failure now tries the configured fallback before giving up.
        if (AIConfig::claudeApiKey() === '' && AIConfig::openAiApiKey() === '') {
            return null;
        }

        $facts    = $this->extractFacts($result);
        $prompt   = PromptLoader::load('advanced_backtest_explanation', [
            'primary_strategy'               => $facts['primary_strategy'],
            'primary_win_rate'                => $facts['primary_win_rate'],
            'primary_profit_factor'           => $facts['primary_profit_factor'],
            'best_strategy'                   => $facts['best_strategy'],
            'best_strategy_win_rate'          => $facts['best_strategy_win_rate'],
            'walk_forward_win_rate'           => $facts['walk_forward_win_rate'] ?? 'n/a (walk-forward could not run — insufficient history)',
            'walk_forward_stability'          => $facts['stability'],
            'walk_forward_dominant_strategy'  => $facts['dominant_strategy'],
            'monte_carlo_p5'                  => $facts['p5'],
            'monte_carlo_p50'                 => $facts['p50'],
            'monte_carlo_p95'                 => $facts['p95'],
            'monte_carlo_loss_probability'    => $facts['loss_probability'],
            'best_target_mult'                => $facts['best_target_mult'] ?? 'n/a',
            'best_stop_mult'                  => $facts['best_stop_mult'] ?? 'n/a',
            'total_signals'                   => $facts['total_signals'],
        ]);

        $response = (new AIManager())->complete(
            $prompt['system'],
            $prompt['user'],
            ['service' => 'ai_advanced_backtest_explanation', 'temperature' => 0.1]
        );

        if ($response === null) {
            return null;
        }

        return $this->parseAiResponse($response->text, $response->provider);
    }

    private function parseAiResponse(string $text, string $provider): ?array
    {
        $text = trim((string) preg_replace('/^```(?:json)?\s*/i', '', $text));
        $text = trim((string) preg_replace('/\s*```\s*$/i', '', $text));

        $data = json_decode($text, true);
        if (!\is_array($data)) {
            error_log("[AiAdvancedBacktestExplanationService] {$provider} returned invalid JSON.");
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

        return $this->build($decision, $confidence, $reasons, $summary, $provider);
    }

    // ── Shared fact extraction ──────────────────────────────────────────────

    private function extractFacts(array $result): array
    {
        $primary       = $result['primary']      ?? ['strategy' => 'unknown', 'summary' => []];
        $comparison    = $result['comparison']   ?? [];
        $walkForward   = $result['walk_forward']  ?? [];
        $monteCarlo    = $result['monte_carlo']   ?? [];
        $optimization  = $result['optimization']  ?? [];

        $primarySummary = $primary['summary'] ?? [];
        $best           = $comparison[0] ?? null;

        $p5  = $monteCarlo['final_equity_percentiles']['p5']  ?? 0.0;
        $p50 = $monteCarlo['final_equity_percentiles']['p50'] ?? 0.0;
        $p95 = $monteCarlo['final_equity_percentiles']['p95'] ?? 0.0;
        $lossProbability = $monteCarlo['probability_of_loss'] ?? (isset($monteCarlo['error']) ? 100.0 : 0.0);

        $bestCombo = $optimization['best'] ?? null;

        // A missing walk-forward result (not enough history for the requested
        // window count) must NEVER be silently read as "0% out-of-sample win
        // rate" — that would misrepresent "no data" as "tested and failed".
        // 'insufficient_data' is a distinct stability value the rule-based
        // decision (and the AI prompt) both branch on explicitly.
        $walkForwardAvailable = !isset($walkForward['error']);

        return [
            'primary_strategy'        => (string) ($primary['strategy'] ?? 'unknown'),
            'primary_win_rate'        => (float) ($primarySummary['win_rate'] ?? 0.0),
            'primary_profit_factor'   => (float) ($primarySummary['profit_factor'] ?? 0.0),
            'total_signals'           => (int) ($primarySummary['total_signals'] ?? 0),
            'best_strategy'           => (string) ($best['strategy'] ?? ($primary['strategy'] ?? 'unknown')),
            'best_strategy_win_rate'  => (float) ($best['summary']['win_rate'] ?? ($primarySummary['win_rate'] ?? 0.0)),
            'walk_forward_win_rate'   => $walkForwardAvailable ? (float) ($walkForward['avg_out_of_sample_win_rate'] ?? 0.0) : null,
            'stability'               => $walkForwardAvailable ? (string) ($walkForward['stability'] ?? 'unknown') : 'insufficient_data',
            'dominant_strategy'       => $walkForwardAvailable ? (string) ($walkForward['dominant_strategy'] ?? 'n/a') : 'n/a',
            'p5'                      => (float) $p5,
            'p50'                     => (float) $p50,
            'p95'                     => (float) $p95,
            'loss_probability'        => (float) $lossProbability,
            'best_target_mult'        => $bestCombo['target_atr_mult'] ?? null,
            'best_stop_mult'          => $bestCombo['stop_atr_mult'] ?? null,
        ];
    }

    // ── Builder ───────────────────────────────────────────────────────────────

    private function build(
        string $decision,
        int $confidence,
        array $reasons,
        string $summary,
        string $provider = 'rule_based'
    ): array {
        return [
            'decision'   => $decision,
            'confidence' => max(0, min(100, $confidence)),
            'summary'    => $summary,
            'reasons'    => array_values(array_slice($reasons, 0, 5)),
            'provider'   => $provider,
            'reliable'   => $decision === 'VALID',
        ];
    }
}
