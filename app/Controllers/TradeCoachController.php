<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Models\AiDecision;
use App\TradeCoach\TradeCoachAiService;
use App\TradeCoach\TradeCoachEngine;

final class TradeCoachController extends Controller
{
    private const VALID_MODES = ['beginner', 'professional'];

    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('trade-coach/index', [
            'title'   => 'AI Trade Coach',
            'csrf'    => Csrf::token(),
            'markets' => configured_markets(),
            'result'  => null,
            'mode'    => 'beginner',
        ]);
    }

    public function explain(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $markets      = configured_markets();
        $validLabels  = array_column($markets, 'label');
        $marketLabel  = trim((string) ($_POST['market_label'] ?? ''));
        if (!\in_array($marketLabel, $validLabels, true)) {
            $marketLabel = $validLabels[0] ?? '';
        }

        $mode = strtolower(trim((string) ($_POST['mode'] ?? 'beginner')));
        if (!\in_array($mode, self::VALID_MODES, true)) {
            $mode = 'beginner';
        }

        $result = null;

        if (!RateLimiter::allow('trade_coach_explain', 10, 60)) {
            $this->view('trade-coach/index', [
                'title'    => 'AI Trade Coach',
                'csrf'     => Csrf::token(),
                'markets'  => $markets,
                'result'   => ['error' => 'Too many requests — please wait a moment and try again.'],
                'mode'     => $mode,
                'selected' => $marketLabel,
            ]);
            return;
        }

        try {
            $decisionRow = AiDecision::latestForMarket($marketLabel, 1)[0] ?? null;

            if ($decisionRow === null) {
                throw new \RuntimeException(
                    "No recorded decision for {$marketLabel} yet — the automatic scanner produces one every cycle. Try again after the next scan."
                );
            }

            $reasoning = TradeCoachEngine::splitLines((string) ($decisionRow['reasoning'] ?? ''));
            $conflicts = TradeCoachEngine::splitLines((string) ($decisionRow['conflicts'] ?? ''));

            $aiNarrative = (new TradeCoachAiService())->narrate(
                $marketLabel,
                (string) $decisionRow['decision'],
                (float) $decisionRow['confidence'],
                (string) $decisionRow['risk_level'],
                $mode,
                $reasoning,
                $conflicts
            );

            $explanation = (new TradeCoachEngine())->explain($decisionRow, $mode, $aiNarrative);

            $result = [
                'explanation'  => $explanation,
                'decision_age' => (string) ($decisionRow['created_at'] ?? ''),
            ];
        } catch (\Throwable $e) {
            error_log('[TradeCoachController] Explain failed: ' . $e->getMessage());
            $result = ['error' => $e->getMessage()];
        }

        $this->view('trade-coach/index', [
            'title'    => 'AI Trade Coach',
            'csrf'     => Csrf::token(),
            'markets'  => $markets,
            'result'   => $result,
            'mode'     => $mode,
            'selected' => $marketLabel,
        ]);
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('TRADE_COACH')) {
            return true;
        }

        $_SESSION['flash_error'] = 'AI Trade Coach is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
