<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Models\MultiTimeframeAnalysis;
use App\MultiTimeframe\MultiTimeframeDataFetcher;
use App\MultiTimeframe\MultiTimeframeDecisionEngine;

final class MultiTimeframeController extends Controller
{
    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('multi-timeframe/index', [
            'title'   => 'Multi-Timeframe',
            'csrf'    => Csrf::token(),
            'markets' => configured_markets(),
            'result'  => null,
        ]);
    }

    public function analyze(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $symbol = trim((string) ($_POST['symbol'] ?? 'EURUSD=X'));
        $validSymbols = array_column(configured_markets(), 'symbol');
        if (!\in_array($symbol, $validSymbols, true)) {
            $symbol = $validSymbols[0] ?? 'EURUSD=X';
        }
        $label = market_label_from_symbol($symbol);

        $result = null;

        if (!RateLimiter::allow('multi_timeframe_analyze', 10, 60)) {
            $this->view('multi-timeframe/index', [
                'title'    => 'Multi-Timeframe',
                'csrf'     => Csrf::token(),
                'markets'  => configured_markets(),
                'result'   => ['error' => 'Too many requests — please wait a moment and try again.'],
                'selected' => $symbol,
            ]);
            return;
        }

        try {
            $byTimeframe = (new MultiTimeframeDataFetcher())->fetchAll($symbol);

            if (empty($byTimeframe)) {
                throw new \RuntimeException('No timeframes could be fetched right now. Try again shortly.');
            }

            $decision = (new MultiTimeframeDecisionEngine())->decide($symbol, $label, $byTimeframe);
            $analysis = $decision->alignment;

            MultiTimeframeAnalysis::create([
                'market_label'              => $label,
                'overall_bias'              => $analysis->overallBias,
                'confidence'                => $analysis->confidence,
                'breakdown_json'            => json_encode($analysis->breakdown, JSON_UNESCAPED_UNICODE),
                'conflicts_json'            => json_encode($analysis->conflicts, JSON_UNESCAPED_UNICODE),
                'pullback_json'             => json_encode($decision->toArray()['pullbacks'], JSON_UNESCAPED_UNICODE),
                'entry_confirmation_json'   => json_encode($decision->entry->toArray(), JSON_UNESCAPED_UNICODE),
                'ai_summary_json'           => json_encode($decision->aiSummary, JSON_UNESCAPED_UNICODE),
                'recommendation'            => $decision->recommendation,
                'recommendation_confidence' => $decision->recommendationConfidence,
            ]);

            $result = [
                'label'           => $label,
                'symbol'          => $symbol,
                'timeframes_used' => count($byTimeframe),
                'analysis'        => $analysis,
                'decision'        => $decision,
            ];
        } catch (\Throwable $e) {
            error_log('[MultiTimeframeController] Analysis failed: ' . $e->getMessage());
            $result = ['error' => $e->getMessage()];
        }

        $this->view('multi-timeframe/index', [
            'title'    => 'Multi-Timeframe',
            'csrf'     => Csrf::token(),
            'markets'  => configured_markets(),
            'result'   => $result,
            'selected' => $symbol,
        ]);
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('MULTI_TIMEFRAME')) {
            return true;
        }

        $_SESSION['flash_error'] = 'Multi-Timeframe analysis is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
