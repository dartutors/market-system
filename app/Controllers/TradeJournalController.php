<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Image\ImageStorage;
use App\Image\ImageValidator;
use App\Image\UploadService;
use App\Models\AiDecision;
use App\Models\SignalEvent;
use App\Models\TradeJournal;
use App\Models\UploadedChart;
use App\Learning\SetupPatternCatalog;
use App\TradeJournal\EmotionCatalog;
use App\TradeJournal\JournalCoachingService;
use App\TradeJournal\MistakeTagCatalog;

final class TradeJournalController extends Controller
{
    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('trade-journal/index', [
            'title'          => 'Trade Journal',
            'csrf'           => Csrf::token(),
            'markets'        => configured_markets(),
            'mistakeTags'    => MistakeTagCatalog::all(),
            'emotions'       => EmotionCatalog::all(),
            'entries'        => TradeJournal::latest(30),
            'stats'          => TradeJournal::stats(),
            'prefill'        => $this->resolvePrefill(),
            'coachingReport' => null,
            'flashOk'        => $_SESSION['journal_flash_ok'] ?? null,
        ]);
        unset($_SESSION['journal_flash_ok']);
    }

    public function store(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $marketLabel = trim((string) ($_POST['market_label'] ?? ''));
        $direction   = strtoupper(trim((string) ($_POST['direction'] ?? ''))) ?: null;
        $direction   = \in_array($direction, ['BUY', 'SELL'], true) ? $direction : null;
        $entryPrice  = self::nullableFloat($_POST['entry_price'] ?? '');
        $notes       = trim((string) ($_POST['notes'] ?? ''));
        $signalId    = self::nullableInt($_POST['signal_id']   ?? '');
        $decisionId  = self::nullableInt($_POST['decision_id'] ?? '');

        $source = 'manual';
        $sessionName = trim((string) ($_POST['session_name'] ?? '')) ?: current_session_name(now_eat());

        if ($signalId !== null) {
            $signal = SignalEvent::findById($signalId);
            if ($signal !== null) {
                $marketLabel = $marketLabel !== '' ? $marketLabel : (string) $signal['market_label'];
                $direction   = $direction ?? (\in_array($signal['action'], ['BUY', 'SELL'], true) ? $signal['action'] : null);
                $sessionName = (string) $signal['session_name'];
                $source      = 'ai_signal';
            }
        } elseif ($decisionId !== null) {
            $decision = AiDecision::findById($decisionId);
            if ($decision !== null) {
                $marketLabel = $marketLabel !== '' ? $marketLabel : (string) $decision['market_label'];
                $direction   = $direction ?? (\in_array($decision['decision'], ['BUY', 'SELL'], true) ? $decision['decision'] : null);
                $source      = 'ai_decision';
            }
        }

        try {
            if ($marketLabel === '') {
                throw new \InvalidArgumentException('A market pair is required to log a trade.');
            }

            $imageId = $this->handleOptionalScreenshot($marketLabel);

            $mistakeTags  = MistakeTagCatalog::sanitize((array) ($_POST['mistake_tags'] ?? []));
            $setupPattern = trim((string) ($_POST['setup_pattern'] ?? ''));
            $setupPattern = SetupPatternCatalog::isValid($setupPattern) ? $setupPattern : null;
            $emotion      = trim((string) ($_POST['emotion'] ?? ''));
            $emotion      = EmotionCatalog::isValid($emotion) ? $emotion : null;

            $id = TradeJournal::create([
                'signal_id'         => $signalId,
                'decision_id'       => $decisionId,
                'image_id'          => $imageId,
                'market_label'      => $marketLabel,
                'direction'         => $direction,
                'emotion'           => $emotion,
                'setup_pattern'     => $setupPattern,
                'source'            => $source,
                'session_name'      => $sessionName,
                'entry_price'       => $entryPrice,
                'notes'             => $notes !== '' ? $notes : null,
                'lessons_learned'   => null,
                'mistake_tags_json' => empty($mistakeTags) ? null : json_encode($mistakeTags, JSON_UNESCAPED_UNICODE),
            ]);

            $_SESSION['journal_flash_ok'] = "Trade #{$id} logged for {$marketLabel}.";
        } catch (\Throwable $e) {
            error_log('[TradeJournalController] store() failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = $e->getMessage();
        }

        redirect(url('trade-journal'));
    }

    public function update(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $entry = $id > 0 ? TradeJournal::findById($id) : null;

        if ($entry === null) {
            $_SESSION['flash_error'] = 'Journal entry not found.';
            redirect(url('trade-journal'));
        }

        $notes          = trim((string) ($_POST['notes'] ?? ''));
        $lessonsLearned = trim((string) ($_POST['lessons_learned'] ?? ''));
        $mistakeTags    = MistakeTagCatalog::sanitize((array) ($_POST['mistake_tags'] ?? []));
        $mistakeJson    = empty($mistakeTags) ? null : json_encode($mistakeTags, JSON_UNESCAPED_UNICODE);
        $setupPattern   = trim((string) ($_POST['setup_pattern'] ?? ''));
        $setupPattern   = SetupPatternCatalog::isValid($setupPattern) ? $setupPattern : null;
        $emotion        = trim((string) ($_POST['emotion'] ?? ''));
        $emotion        = EmotionCatalog::isValid($emotion) ? $emotion : null;

        $outcome   = trim((string) ($_POST['outcome'] ?? ''));
        $exitPrice = self::nullableFloat($_POST['exit_price'] ?? '');

        if ($outcome !== '' && \in_array($outcome, TradeJournal::validOutcomes(), true)) {
            $entryPrice = self::nullableFloat($entry['entry_price'] ?? '');
            $profitLoss = self::nullableFloat($_POST['profit_loss'] ?? '');
            $pips       = self::nullableFloat($_POST['pips'] ?? '');

            TradeJournal::close($id, [
                'exit_price'        => $exitPrice,
                'profit_loss'       => $profitLoss,
                'pips'              => $pips,
                'outcome'           => $outcome,
                'mistake_tags_json' => $mistakeJson,
                'lessons_learned'   => $lessonsLearned !== '' ? $lessonsLearned : null,
                'setup_pattern'     => $setupPattern,
            ]);

            if ($emotion !== null) {
                TradeJournal::update($id, ['emotion' => $emotion]);
            }

            // Best-effort — a failed/unavailable AI call here should never
            // block the trade from actually being closed (already committed
            // above), same "narration is optional, the record isn't" stance
            // as every other AI feature in this app.
            $closed = TradeJournal::findById($id);
            try {
                if ($closed !== null) {
                    $feedback = (new JournalCoachingService())->perEntryFeedback($closed);
                    if ($feedback !== null) {
                        TradeJournal::saveAiFeedback($id, $feedback);
                    }
                }
            } catch (\Throwable $e) {
                error_log("[TradeJournalController] perEntryFeedback failed for #{$id}: " . $e->getMessage());
            }

            // The structured report card (Objective 4) is rule-based, not
            // an AI call — always computed on close, no availability gate
            // needed the way the freeform AI feedback above has one.
            try {
                if ($closed !== null) {
                    $review = (new \App\Services\AiTradingCoachService())->reviewClosedTrade($closed);
                    TradeJournal::saveReportCard($id, json_encode($review['scores'], JSON_UNESCAPED_UNICODE), $review['overall_grade']);
                }
            } catch (\Throwable $e) {
                error_log("[TradeJournalController] reviewClosedTrade failed for #{$id}: " . $e->getMessage());
            }

            $_SESSION['journal_flash_ok'] = "Trade #{$id} closed as " . strtoupper($outcome) . '.';
        } else {
            TradeJournal::update($id, [
                'notes'             => $notes !== '' ? $notes : null,
                'lessons_learned'   => $lessonsLearned !== '' ? $lessonsLearned : null,
                'mistake_tags_json' => $mistakeJson,
                'setup_pattern'     => $setupPattern,
                'emotion'           => $emotion,
            ]);
            $_SESSION['journal_flash_ok'] = "Trade #{$id} updated.";
        }

        redirect(url('trade-journal'));
    }

    public function coachingReport(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $report = null;
        try {
            $report = (new JournalCoachingService())->report();
        } catch (\Throwable $e) {
            error_log('[TradeJournalController] coachingReport() failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not generate a coaching report right now.';
        }

        $this->view('trade-journal/index', [
            'title'          => 'Trade Journal',
            'csrf'           => Csrf::token(),
            'markets'        => configured_markets(),
            'mistakeTags'    => MistakeTagCatalog::all(),
            'emotions'       => EmotionCatalog::all(),
            'entries'        => TradeJournal::latest(30),
            'stats'          => TradeJournal::stats(),
            'prefill'        => $this->resolvePrefill(),
            'coachingReport' => $report,
            'flashOk'        => null,
        ]);
    }

    public function archive(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $markets     = configured_markets();
        $marketLabel = trim((string) ($_GET['market_label'] ?? ''));
        $validLabels = array_column($markets, 'label');

        $decisions = \in_array($marketLabel, $validLabels, true)
            ? AiDecision::latestForMarket($marketLabel, 30)
            : AiDecision::latest(30);

        $this->view('trade-journal/archive', [
            'title'    => 'AI Recommendation Archive',
            'markets'  => $markets,
            'selected' => $marketLabel,
            'decisions' => $decisions,
        ]);
    }

    /**
     * @return array{market_label:string, direction:string, session_name:string, signal_id:?int, decision_id:?int}|null
     */
    private function resolvePrefill(): ?array
    {
        $signalId   = self::nullableInt($_GET['log_signal_id']   ?? '');
        $decisionId = self::nullableInt($_GET['log_decision_id'] ?? '');

        if ($signalId !== null) {
            $signal = SignalEvent::findById($signalId);
            if ($signal !== null) {
                return [
                    'market_label' => (string) $signal['market_label'],
                    'direction'    => (string) $signal['action'],
                    'session_name' => (string) $signal['session_name'],
                    'signal_id'    => $signalId,
                    'decision_id'  => null,
                ];
            }
        } elseif ($decisionId !== null) {
            $decision = AiDecision::findById($decisionId);
            if ($decision !== null) {
                return [
                    'market_label' => (string) $decision['market_label'],
                    'direction'    => (string) $decision['decision'],
                    'session_name' => current_session_name(now_eat()),
                    'signal_id'    => null,
                    'decision_id'  => $decisionId,
                ];
            }
        }

        return null;
    }

    /** Reuses the Upload -> Validate -> Store pipeline stages from App\Image (no ImageProcessor — that stage exists to shrink images before an AI vision call, which a plain screenshot attachment doesn't need). */
    private function handleOptionalScreenshot(string $marketLabel): ?int
    {
        $file = $_FILES['screenshot'] ?? null;
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $stagedPath = (new UploadService())->stage($file);
        try {
            $validPath     = (new ImageValidator())->handle($stagedPath);
            $permanentPath = (new ImageStorage())->persist($validPath);
        } catch (\Throwable $e) {
            // Unlike VisionController's slot loop, this method has no local
            // catch of its own upstream — a rejected upload would otherwise
            // leave its staged file in storage/uploads/tmp forever.
            if (is_file($stagedPath)) {
                @unlink($stagedPath);
            }
            throw $e;
        }

        $dimensions = @getimagesize($permanentPath);

        return UploadedChart::create([
            'market_label' => $marketLabel,
            'timeframe'    => '',
            'file_path'    => $permanentPath,
            'mime_type'    => 'image/' . strtolower((string) pathinfo($permanentPath, PATHINFO_EXTENSION) ?: 'png'),
            'width'        => $dimensions[0] ?? 0,
            'height'       => $dimensions[1] ?? 0,
            'source'       => 'trade_journal',
            'uploaded_by'  => $_SESSION['user_id'] ?? null,
        ]);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        $value = trim((string) $value);
        return $value === '' ? null : (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        return $value === '' ? null : (int) $value;
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('TRADE_JOURNAL')) {
            return true;
        }

        $_SESSION['flash_error'] = 'Trade Journal is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
