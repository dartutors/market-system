<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Consensus\DecisionPerformanceAnalyzer;
use App\Consensus\EvidenceWeights;
use App\Consensus\WeightOptimizerService;
use App\Core\Controller;
use App\Core\Csrf;
use App\Learning\ConfidenceCalibrationService;
use App\Learning\LearningEngine;
use App\Learning\PatternLearningService;
use App\Models\ConsensusDecision;
use App\Models\EvidenceSourceWeight;
use App\Models\WeightChangeProposal;
use App\Services\AiTradingCoachService;
use Throwable;

final class LearningController extends Controller
{
    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $coach = new AiTradingCoachService();

        $this->view('learning/index', [
            'title'            => 'AI Learning Engine',
            'csrf'             => Csrf::token(),
            'overview'         => (new LearningEngine())->buildOverview(),
            'coachMetrics'      => $coach->learningDashboardMetrics(),
            'personalInsights'  => $coach->personalInsights(),
            'achievements'      => $coach->achievements(),
            'flashOk'          => $_SESSION['learning_flash_ok'] ?? null,
        ]);
        unset($_SESSION['learning_flash_ok']);
    }

    public function rebuildCalibration(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        try {
            $data = (new ConfidenceCalibrationService())->remember();
            $_SESSION['learning_flash_ok'] = "Confidence calibration memory updated from {$data['sample_size']} signal(s).";
        } catch (\Throwable $e) {
            error_log('[LearningController] rebuildCalibration() failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not rebuild confidence calibration right now.';
        }

        redirect(url('learning'));
    }

    public function rebuildPatterns(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        try {
            $grouped = (new PatternLearningService())->rebuild();
            $_SESSION['learning_flash_ok'] = count($grouped) . ' pattern statistic row(s) rebuilt from your Trade Journal.';
        } catch (\Throwable $e) {
            error_log('[LearningController] rebuildPatterns() failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not rebuild pattern statistics right now.';
        }

        redirect(url('learning'));
    }

    /**
     * Institutional Accuracy Dashboard — per-source accuracy, overall win
     * rate, session-segmented accuracy, and a weekly trend, all computed
     * honestly from resolved consensus_decisions (App\Consensus\
     * DecisionPerformanceAnalyzer) rather than asserted. Includes an
     * explicit disclosure for the one question this data genuinely cannot
     * answer yet (Vision accuracy split by individual timeframe).
     */
    public function accuracyDashboard(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $analyzer = new DecisionPerformanceAnalyzer();

        $this->view('learning/accuracy', [
            'title'       => 'Institutional Accuracy Dashboard',
            'overall'     => $analyzer->overall(),
            'bySource'    => $analyzer->bySource(),
            'bySession'   => $analyzer->overallBySession(),
            'trend'       => $analyzer->weeklyTrend(),
            'visionNote'  => $analyzer->visionTimeframeAccuracyNote(),
            'calibration' => (new ConfidenceCalibrationService())->calculate(),
        ]);
    }

    /** List of historical decisions to replay — most recent first. */
    public function replayIndex(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('learning/replay_index', [
            'title'     => 'AI Decision Replay',
            'decisions' => ConsensusDecision::recent(100),
        ]);
    }

    /**
     * Step-by-step replay of one historical decision — News, Calendar,
     * Technical, AI Vision, Smart Money, ... through to the Consensus and
     * the actual market outcome. Every field comes back out of the frozen
     * sources_json/trade_plan_json/outcome this decision was persisted
     * with at the time — nothing here is re-derived or re-simulated, so
     * the replay always shows exactly what the system actually saw and
     * said, not a reconstruction.
     */
    public function replay(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $id = (int) ($_GET['id'] ?? 0);
        $row = $id > 0 ? ConsensusDecision::findById($id) : null;
        if ($row === null) {
            $_SESSION['flash_error'] = 'That decision could not be found.';
            redirect(url('learning/replay'));
        }

        $this->view('learning/replay', [
            'title'        => 'Decision Replay — ' . $row['market_label'],
            'decision'     => $row,
            'sources'      => json_decode((string) $row['sources_json'], true) ?: [],
            'disagreement' => json_decode((string) $row['disagreement_json'], true) ?: [],
            'conflicts'    => json_decode((string) $row['conflicts_json'], true) ?: [],
            'tradePlan'    => json_decode((string) $row['trade_plan_json'], true),
            'invalidation' => json_decode((string) $row['invalidation_conditions_json'], true) ?: [],
        ]);
    }

    /**
     * Admin-only approval queue for App\Consensus\WeightOptimizerService's
     * proposals — Phase 24's explicit constraint: "Any recommended changes
     * should be transparent, reviewable, and require administrator approval
     * before they take effect." Nothing on this page or its POST actions
     * applies a weight change silently; approve/reject are the only two
     * ways a proposal ever leaves 'pending'.
     */
    public function weightProposals(): void
    {
        require_admin();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('learning/weight_proposals', [
            'title'          => 'AI Weight Optimizer',
            'csrf'           => Csrf::token(),
            'pending'        => WeightChangeProposal::pending(),
            'recent'         => WeightChangeProposal::recent(30),
            'currentWeights' => EvidenceSourceWeight::all(),
            'flashOk'        => $_SESSION['learning_flash_ok'] ?? null,
        ]);
        unset($_SESSION['learning_flash_ok']);
    }

    /** Re-runs the historical simulation and creates any new proposals it can honestly support — never applies anything itself. */
    public function runWeightOptimizer(): void
    {
        require_admin();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        try {
            $created = (new WeightOptimizerService())->generateProposals();
            $_SESSION['learning_flash_ok'] = $created > 0
                ? "{$created} new weight-change proposal(s) generated for review below."
                : 'No new proposals — either too little resolved history yet, or no simulated reweighting would have meaningfully changed past outcomes.';
        } catch (Throwable $e) {
            error_log('[LearningController] runWeightOptimizer() failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not run the weight optimizer right now.';
        }

        redirect(url('learning/weight-proposals'));
    }

    /** The only place a proposed weight ever actually takes effect — and only on explicit admin approval. */
    public function decideWeightProposal(): void
    {
        require_admin();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $proposal = $id > 0 ? WeightChangeProposal::findById($id) : null;

        if ($proposal === null || $proposal['status'] !== 'pending') {
            $_SESSION['flash_error'] = 'That proposal was not found or has already been decided.';
            redirect(url('learning/weight-proposals'));
        }

        if ($action === 'approve') {
            try {
                EvidenceSourceWeight::set((string) $proposal['source_key'], (float) $proposal['proposed_weight']);
                EvidenceWeights::clearCache();
                WeightChangeProposal::decide($id, 'approved', $_SESSION['user_id'] ?? null);
                $_SESSION['learning_flash_ok'] = "Approved — {$proposal['source_key']} weight changed from {$proposal['current_weight']} to {$proposal['proposed_weight']}.";
            } catch (Throwable $e) {
                error_log('[LearningController] approve weight proposal failed: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'Could not apply that weight change right now.';
            }
        } elseif ($action === 'reject') {
            WeightChangeProposal::decide($id, 'rejected', $_SESSION['user_id'] ?? null);
            $_SESSION['learning_flash_ok'] = "Rejected the proposal for {$proposal['source_key']} — weight left unchanged.";
        } else {
            $_SESSION['flash_error'] = 'Unknown action.';
        }

        redirect(url('learning/weight-proposals'));
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('AI_LEARNING')) {
            return true;
        }

        $_SESSION['flash_error'] = 'AI Learning Engine is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
