<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Services\DecisionIntelligenceEngine;
use App\Services\MarketScanService;

final class AdminToolsController extends Controller
{
    public function refreshConsensus(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        // Up to 2 AI calls (Claude + OpenAI) per configured market — this
        // normally runs from cron/consensus_refresh.php every 30 minutes;
        // this is the on-demand "I don't want to wait" version, matching
        // runScan()'s own manual-trigger pattern below.
        set_time_limit(120);

        try {
            $results = (new DecisionIntelligenceEngine())->decideAll(configured_markets());
            $summary = [];
            foreach ($results as $label => $result) {
                $summary[] = "{$label}: {$result['overall_decision']} ({$result['consensus_score']}%)";
            }
            $_SESSION['flash_success'] = 'Consensus refreshed — ' . implode(' · ', $summary);
        } catch (\Throwable $e) {
            error_log('[AdminToolsController] refreshConsensus failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Consensus refresh failed — check the logs.';
        }

        redirect(url('dashboard'));
    }

    public function runScan(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $result  = (new MarketScanService())->run();
        $status  = strtolower((string) ($result['status'] ?? 'ok'));
        $message = trim((string) ($result['message'] ?? 'Scan completed.'));
        $details = trim((string) ($result['details'] ?? ''));

        // Hard error — scan pipeline threw an exception
        if ($status === 'error') {
            $_SESSION['flash_error'] = $message !== '' ? $message : 'Scan failed.';
            redirect(url('dashboard'));
        }

        // Risk guard blocked the scan (daily cap, etc.)
        if ($status === 'skipped') {
            $_SESSION['flash_error'] = $message !== '' ? $message : 'Scan skipped.';
            redirect(url('dashboard'));
        }

        // Determine whether any market produced an actionable BUY or SELL signal.
        // The details string contains per-market summaries like:
        //   "EUR/USD: WAIT — session closed [WAIT] | GBP/USD: BUY (77.5%) [ENTRY]"
        // We check for ': BUY' or ': SELL' patterns in the details.
        $detailsUpper = strtoupper($details);
        $hasSignal    = str_contains($detailsUpper, ': BUY')
                     || str_contains($detailsUpper, ': SELL');

        if (!$hasSignal) {
            // No actionable signals across any market
            $display = 'WAIT — market conditions are not strong enough yet.';
            if ($details !== '') {
                // Show first 250 chars of details as context
                $display .= ' (' . mb_substr($details, 0, 250) . ')';
            }
            $_SESSION['flash_success'] = $display;
            redirect(url('dashboard'));
        }

        // At least one market produced a BUY/SELL signal
        $_SESSION['flash_success'] = $message !== '' ? $message : 'Scan completed.';
        if ($details !== '') {
            $_SESSION['flash_success'] .= ' — ' . mb_substr($details, 0, 300);
        }
        redirect(url('dashboard'));
    }
}
