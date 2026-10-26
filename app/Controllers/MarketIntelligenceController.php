<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\MarketIntelligence\MarketIntelligenceEngine;

final class MarketIntelligenceController extends Controller
{
    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $result = null;

        try {
            $result = ['snapshot' => (new MarketIntelligenceEngine())->buildCached()];
        } catch (\Throwable $e) {
            error_log('[MarketIntelligenceController] build() failed: ' . $e->getMessage());
            $result = ['error' => $e->getMessage()];
        }

        $this->view('market-intelligence/index', [
            'title'  => 'Market Intelligence',
            'result' => $result,
        ]);
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('ADVANCED_ANALYTICS')) {
            return true;
        }

        $_SESSION['flash_error'] = 'Market Intelligence is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
