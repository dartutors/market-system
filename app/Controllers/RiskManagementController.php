<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Risk\LiveAtrLookup;
use App\Risk\RiskAssessmentEngine;

final class RiskManagementController extends Controller
{
    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('risk-management/index', [
            'title'   => 'Risk Management',
            'csrf'    => Csrf::token(),
            'markets' => configured_markets(),
            'result'  => null,
        ]);
    }

    public function assess(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $markets     = configured_markets();
        $validLabels = array_column($markets, 'label');

        $marketLabel = trim((string) ($_POST['market_label'] ?? ''));
        if (!\in_array($marketLabel, $validLabels, true)) {
            $marketLabel = $validLabels[0] ?? '';
        }

        $direction   = strtoupper(trim((string) ($_POST['direction'] ?? 'BUY'))) === 'SELL' ? 'SELL' : 'BUY';
        $balance     = max(0.0, (float) ($_POST['balance'] ?? 0));
        $riskPercent = min(100.0, max(0.01, (float) ($_POST['risk_percent'] ?? 1.0)));
        $entryPrice  = (float) ($_POST['entry_price'] ?? 0);

        $stopPrice   = self::nullableFloat($_POST['stop_price']   ?? '');
        $stopPips    = self::nullableFloat($_POST['stop_pips']    ?? '');
        $targetPrice = self::nullableFloat($_POST['target_price'] ?? '');

        $result = null;

        try {
            if ($entryPrice <= 0.0) {
                throw new \InvalidArgumentException('Enter a valid entry price to size this trade.');
            }

            $atr14 = null;
            if ($stopPrice === null && $stopPips === null) {
                $atr14 = (new LiveAtrLookup())->forMarketLabel($marketLabel);
            }

            $assessment = (new RiskAssessmentEngine())->assess(
                marketLabel: $marketLabel,
                direction: $direction,
                balance: $balance,
                riskPercent: $riskPercent,
                entryPrice: $entryPrice,
                stopPrice: $stopPrice,
                stopPips: $stopPips,
                atr14: $atr14,
                targetPrice: $targetPrice,
            );

            $result = ['assessment' => $assessment];
        } catch (\Throwable $e) {
            error_log('[RiskManagementController] Assessment failed: ' . $e->getMessage());
            $result = ['error' => $e->getMessage()];
        }

        $this->view('risk-management/index', [
            'title'    => 'Risk Management',
            'csrf'     => Csrf::token(),
            'markets'  => $markets,
            'result'   => $result,
            'selected' => $marketLabel,
        ]);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        $value = trim((string) $value);
        return $value === '' ? null : (float) $value;
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('RISK_MANAGEMENT')) {
            return true;
        }

        $_SESSION['flash_error'] = 'Risk Management is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
