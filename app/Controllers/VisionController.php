<?php
declare(strict_types=1);
namespace App\Controllers;

use App\AI\PromptLoader;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Image\ImageProcessor;
use App\Image\ImageStorage;
use App\Image\ImageValidator;
use App\Image\UploadService;
use App\Image\VisionManager;
use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\Models\ChartAnalysis;
use App\Models\UploadedChart;
use App\Models\VisionSession;
use App\Patterns\Models\PatternMatch;
use App\Patterns\PatternRecognitionEngine;
use App\Risk\LevelPicker;
use App\Risk\LiveAtrLookup;
use App\Risk\RiskAssessment;
use App\Risk\RiskAssessmentEngine;
use App\Services\AiVisionSynthesisService;
use App\Vision\CompletenessScorer;
use App\Vision\TopDownAnalysisEngine;
use App\Vision\VisionDecisionEngine;
use App\Vision\VisionTimeframeCatalog;

/**
 * Phase 3.2 — Multi-Timeframe AI Vision Workspace. Analyzes up to 9
 * independently-uploaded chart screenshots (one per App\Vision\
 * VisionTimeframeCatalog label), one AI vision call per uploaded slot
 * (reusing the exact single-image pipeline this controller always had —
 * UploadService/ImageValidator/ImageStorage/ImageProcessor/VisionManager,
 * unchanged), tolerating per-slot failures, then combines the successfully
 * analyzed slots via TopDownAnalysisEngine -> CompletenessScorer ->
 * VisionDecisionEngine -> AiVisionSynthesisService into one top-down
 * assessment and a single BUY|SELL|WAIT|NO_TRADE call.
 */
final class VisionController extends Controller
{
    private const VALID_DECISIONS = ['BUY', 'SELL', 'WAIT', 'NO_TRADE'];
    private const DETECTION_CONFIDENCE_FLOOR = 90.0;

    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('vision/index', [
            'title'                    => 'AI Vision — Multi-Timeframe Workspace',
            'csrf'                     => Csrf::token(),
            'timeframes'               => VisionTimeframeCatalog::labels(),
            'result'                   => null,
            'risk_management_enabled' => feature_enabled('RISK_MANAGEMENT'),
            'pairHint'                 => trim((string) ($_GET['pair'] ?? '')),
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

        // Up to 9 real AI vision calls per request (one per uploaded
        // timeframe slot) — the single most AI-cost-expensive endpoint in
        // this app, so it gets the tightest per-request budget: 5 analyze
        // requests per 5 minutes, vs. the 10/60s used for the much cheaper
        // single-call News explain endpoint.
        if (!RateLimiter::allow('vision_analyze', 5, 300)) {
            $this->view('vision/index', [
                'title'                    => 'AI Vision — Multi-Timeframe Workspace',
                'csrf'                     => Csrf::token(),
                'timeframes'               => VisionTimeframeCatalog::labels(),
                'result'                   => ['error' => 'Too many analysis requests — please wait a few minutes and try again.'],
                'risk_management_enabled' => feature_enabled('RISK_MANAGEMENT'),
                'pairHint'                 => '',
            ]);
            return;
        }

        $marketHint = trim((string) ($_POST['market_hint'] ?? ''));
        $result     = null;

        try {
            $slots = [];
            foreach (VisionTimeframeCatalog::labels() as $label) {
                $fieldName = 'chart_' . strtolower($label);
                $file      = $_FILES[$fieldName] ?? null;
                if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $slots[$label] = $file;
            }

            if (empty($slots)) {
                throw new \RuntimeException('Please upload at least one chart screenshot to analyze.');
            }

            // ── Analyze every provided slot independently, tolerating per-slot
            // failure without aborting the whole batch (mirrors
            // MultiTimeframeDataFetcher::fetchAll()'s try/catch-and-skip loop).
            $slotResults = [];
            foreach ($slots as $label => $file) {
                $slotResults[$label] = $this->analyzeSlot($label, $file, $marketHint);
            }

            $byTimeframe   = [];
            $imageIds      = [];
            $detectedPairs = [];
            foreach ($slotResults as $label => $slotResult) {
                if (isset($slotResult['error'])) {
                    continue;
                }
                $byTimeframe[$label] = $slotResult['parsed'];
                $imageIds[]          = $slotResult['image_id'];
                $detectedPair        = trim((string) ($slotResult['parsed']['detected_pair'] ?? ''));
                if ($detectedPair !== '') {
                    $detectedPairs[strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $detectedPair) ?? '')] = $detectedPair;
                }
            }

            if (empty($byTimeframe)) {
                throw new \RuntimeException('None of the uploaded charts could be analyzed. Please check the images and try again.');
            }

            $pairWarning = null;
            if (\count($detectedPairs) > 1) {
                $pairWarning = 'Uploaded charts show different pairs (' . implode(', ', $detectedPairs)
                    . ') — the analysis below assumes they are all the same instrument.';
            }

            $marketLabel = $marketHint !== '' ? $marketHint : (string) (reset($detectedPairs) ?: '—');

            $topDown      = (new TopDownAnalysisEngine())->analyze($byTimeframe);
            $completeness = CompletenessScorer::score(array_keys($byTimeframe));
            $decision     = (new VisionDecisionEngine())->decide($topDown, $completeness, $byTimeframe);
            $synthesis    = (new AiVisionSynthesisService())->synthesize($marketLabel, $byTimeframe, $topDown, $completeness, $decision);

            $sessionId = VisionSession::create([
                'market_label'             => $marketLabel,
                'timeframes_uploaded_json' => json_encode(array_keys($byTimeframe), JSON_UNESCAPED_UNICODE),
                'completeness_score'       => $completeness['percentage'],
                'overall_trend'            => $topDown['overall_trend'],
                'decision'                 => $decision['decision'],
                'confidence'               => $decision['confidence'],
                'risk_level'               => $decision['risk_level'],
                'synthesis_json'           => json_encode($synthesis, JSON_UNESCAPED_UNICODE) ?: '{}',
                'provider'                 => $synthesis['provider'],
                'created_by'               => $_SESSION['user_id'] ?? null,
            ]);
            UploadedChart::attachToSession($imageIds, $sessionId);

            [$riskAssessment, $riskAssessmentMessage] = $this->buildRiskGuidance($byTimeframe, $topDown, $marketLabel);

            $result = [
                'session_id'              => $sessionId,
                'market_label'            => $marketLabel,
                'pair_warning'            => $pairWarning,
                'slots'                   => $slotResults,
                'completeness'            => $completeness,
                'top_down'                => $topDown,
                'decision'                => $decision,
                'synthesis'               => $synthesis,
                'risk_assessment'         => $riskAssessment,
                'risk_assessment_message' => $riskAssessmentMessage,
            ];

            // Best-effort — a failed alert must never affect the analysis
            // result the user is about to see.
            try {
                (new \App\Services\TelegramNotifier())->sendToAdmin(
                    "AI Vision analysis completed — {$marketLabel}\n"
                    . "Decision: {$decision['decision']} ({$decision['confidence']}%, {$decision['risk_level']} risk)"
                );
            } catch (\Throwable $e) {
                error_log('[VisionController] Telegram alert failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('[VisionController] Analysis failed: ' . $e->getMessage());
            $result = ['error' => $e->getMessage()];
        }

        $this->view('vision/index', [
            'title'                    => 'AI Vision — Multi-Timeframe Workspace',
            'csrf'                     => Csrf::token(),
            'timeframes'               => VisionTimeframeCatalog::labels(),
            'result'                   => $result,
            'risk_management_enabled' => feature_enabled('RISK_MANAGEMENT'),
        ]);
    }

    public function image(): void
    {
        require_login();

        $id    = (int) ($_GET['id'] ?? 0);
        $chart = $id > 0 ? UploadedChart::findById($id) : null;

        if ($chart === null || !is_file((string) $chart['file_path'])) {
            http_response_code(404);
            exit('Image not found.');
        }

        header('Content-Type: ' . (string) $chart['mime_type']);
        header('Content-Length: ' . (string) filesize((string) $chart['file_path']));
        header('Cache-Control: private, max-age=3600');
        readfile((string) $chart['file_path']);
    }

    /**
     * Runs one uploaded screenshot through the unchanged single-image
     * pipeline (stage -> validate -> persist -> process -> AI vision call
     * -> parse), using the slot's own KNOWN timeframe label as the hint
     * (deterministic, not an AI guess) plus the shared pair hint. Also
     * re-runs the existing algorithmic pattern cross-check for this slot,
     * now resolved from the known label instead of a detected/hinted guess.
     *
     * @return array{error:string}|array{label:string, image_id:int, parsed:array, provider:string, algorithmic_patterns:array, needs_confirmation:bool}
     */
    private function analyzeSlot(string $label, array $file, string $marketHint): array
    {
        $stagedPath = null;
        try {
            $stagedPath    = (new UploadService())->stage($file);
            $validPath     = (new ImageValidator())->handle($stagedPath);
            $permanentPath = (new ImageStorage())->persist($validPath);
            $stagedPath    = null; // persist() renamed it away — nothing left in staging to clean up
            $processed     = (new ImageProcessor())->process($permanentPath);

            $prompt = PromptLoader::load('vision_analysis', [
                'market_hint'    => $marketHint,
                'timeframe_hint' => $label,
            ]);

            $response = (new VisionManager())->analyze($processed->path, $prompt['system'], $prompt['user']);
            @unlink($processed->path);

            if ($response === null) {
                return ['error' => "AI Vision analysis is unavailable for the {$label} chart right now (no provider configured or the call failed)."];
            }

            $parsed = $this->parseVisionResponse($response->text);
            if ($parsed === null) {
                return ['error' => "The AI returned a response for the {$label} chart that could not be understood."];
            }

            $algorithmicPatterns = $this->detectAlgorithmicPatterns(
                $marketHint !== '' ? $marketHint : (string) ($parsed['detected_pair'] ?? ''),
                $label
            );

            $imageId = UploadedChart::create([
                'market_label' => $marketHint !== '' ? $marketHint : (string) ($parsed['detected_pair'] ?? ''),
                'timeframe'    => $label,
                'file_path'    => $permanentPath,
                'mime_type'    => 'image/' . strtolower((string) pathinfo($permanentPath, PATHINFO_EXTENSION) ?: 'png'),
                'width'        => $processed->width,
                'height'       => $processed->height,
                'source'       => 'manual_upload',
                'uploaded_by'  => $_SESSION['user_id'] ?? null,
            ]);

            ChartAnalysis::create([
                'image_id'                   => $imageId,
                'provider'                   => $response->provider,
                'model'                      => $response->model,
                'trend'                      => (string) ($parsed['trend'] ?? 'neutral'),
                'indicators_observed_json'   => json_encode($parsed['indicators_observed']   ?? [], JSON_UNESCAPED_UNICODE),
                'support_levels_json'        => json_encode($parsed['support_levels']        ?? [], JSON_UNESCAPED_UNICODE),
                'resistance_levels_json'     => json_encode($parsed['resistance_levels']     ?? [], JSON_UNESCAPED_UNICODE),
                'trendlines_json'            => json_encode($parsed['trendlines']             ?? [], JSON_UNESCAPED_UNICODE),
                'liquidity_zones_json'       => json_encode($parsed['liquidity_zones']        ?? [], JSON_UNESCAPED_UNICODE),
                'candlestick_patterns_json'  => json_encode($parsed['candlestick_patterns']   ?? [], JSON_UNESCAPED_UNICODE),
                'chart_patterns_json'        => json_encode($parsed['chart_patterns']         ?? [], JSON_UNESCAPED_UNICODE),
                'chart_patterns_algorithmic_json' => json_encode($algorithmicPatterns, JSON_UNESCAPED_UNICODE),
                'summary'                    => (string) ($parsed['summary'] ?? ''),
                'recommendation_decision'    => $parsed['recommendation']['decision']   ?? 'NO_TRADE',
                'recommendation_confidence'  => (float) ($parsed['recommendation']['confidence'] ?? 0),
                'recommendation_reasoning'   => (string) ($parsed['recommendation']['reasoning']  ?? ''),
                'confidence'                 => (float) ($parsed['recommendation']['confidence'] ?? 0),
            ]);

            $detectionConfidence = (float) ($parsed['detection_confidence'] ?? 100.0);

            return [
                'label'                => $label,
                'image_id'             => $imageId,
                'parsed'               => $parsed,
                'provider'             => $response->provider,
                'algorithmic_patterns' => $algorithmicPatterns,
                'needs_confirmation'   => $detectionConfidence < self::DETECTION_CONFIDENCE_FLOOR,
            ];
        } catch (\Throwable $e) {
            // A rejected/failed upload previously left its staged file in
            // storage/uploads/tmp forever — nothing ever swept that
            // directory. Validation/persist failures are the only paths
            // that reach here with a still-staged file ($stagedPath is
            // nulled out once persist() successfully moves it away).
            if ($stagedPath !== null && is_file($stagedPath)) {
                @unlink($stagedPath);
            }
            error_log("[VisionController] Slot '{$label}' analysis failed: " . $e->getMessage());
            return ['error' => "The {$label} chart could not be analyzed: " . $e->getMessage()];
        }
    }

    /**
     * Same risk-sizing logic as before, now fed the LOWEST successfully-
     * analyzed timeframe's parsed result — current price/levels are most
     * relevant at the execution timeframe, which is where a trader would
     * actually place the order. Explicit design choice, not a silent change.
     *
     * @param array<string, array<string, mixed>> $byTimeframe
     * @return array{0: ?RiskAssessment, 1: ?string}
     */
    private function buildRiskGuidance(array $byTimeframe, array $topDown, string $marketLabel): array
    {
        if (!feature_enabled('RISK_MANAGEMENT')) {
            return [null, null];
        }

        $lowestLabel = null;
        foreach (array_reverse(VisionTimeframeCatalog::labels()) as $label) {
            if (isset($byTimeframe[$label])) {
                $lowestLabel = $label;
                break;
            }
        }
        if ($lowestLabel === null) {
            return [null, 'No analyzed timeframe was available to size risk guidance from.'];
        }

        $parsed   = $byTimeframe[$lowestLabel];
        $decision = strtoupper(trim((string) ($parsed['recommendation']['decision'] ?? 'NO_TRADE')));

        if (!\in_array($decision, ['BUY', 'SELL'], true)) {
            return [null, "Risk guidance applies to BUY/SELL reads only — the {$lowestLabel} chart (used for sizing) read as {$decision}."];
        }

        if ($marketLabel === '' || $marketLabel === '—') {
            return [null, 'No pair was detected or hinted, so risk guidance could not be sized for this chart.'];
        }

        $entryPrice = self::nullableFloat($_POST['entry_price'] ?? '') ?? self::nullableFloat($parsed['current_price'] ?? '');
        if ($entryPrice === null || $entryPrice <= 0.0) {
            return [null, "The AI could not read a current price off the {$lowestLabel} chart — provide one manually to get risk guidance."];
        }

        $supportLevels    = LevelPicker::numericLevels($parsed['support_levels']    ?? []);
        $resistanceLevels = LevelPicker::numericLevels($parsed['resistance_levels'] ?? []);
        [$levelStop, $levelTarget] = LevelPicker::nearestStopAndTarget($decision, $entryPrice, $supportLevels, $resistanceLevels);

        $stopPrice   = self::nullableFloat($_POST['stop_price']   ?? '') ?? $levelStop;
        $stopPips    = self::nullableFloat($_POST['stop_pips']    ?? '');
        $targetPrice = self::nullableFloat($_POST['target_price'] ?? '') ?? $levelTarget;
        $balance     = max(0.0, (float) ($_POST['balance']      ?? 1000.0));
        $riskPercent = min(100.0, max(0.01, (float) ($_POST['risk_percent'] ?? 1.0)));

        try {
            $atr14 = null;
            if ($stopPrice === null && $stopPips === null) {
                $atr14 = (new LiveAtrLookup())->forMarketLabel($marketLabel);
            }

            $assessment = (new RiskAssessmentEngine())->assess(
                marketLabel: $marketLabel,
                direction: $decision,
                balance: $balance,
                riskPercent: $riskPercent,
                entryPrice: $entryPrice,
                stopPrice: $stopPrice,
                stopPips: $stopPips,
                atr14: $atr14,
                targetPrice: $targetPrice,
            );

            return [$assessment, null];
        } catch (\Throwable $e) {
            error_log('[VisionController] Risk guidance skipped: ' . $e->getMessage());
            return [null, 'Risk guidance could not be computed: ' . $e->getMessage()];
        }
    }

    /**
     * Cross-checks the LLM's screenshot-based pattern read against
     * App\Patterns\PatternRecognitionEngine run over real OHLC data for the
     * same pair/timeframe. Purely additive: any failure (unresolvable pair,
     * unresolvable timeframe, live data unavailable, a detector throwing)
     * just yields no algorithmic patterns; it never blocks the slot's result.
     *
     * @return array<int, array<string, mixed>>
     */
    private function detectAlgorithmicPatterns(string $marketHint, string $timeframeLabel): array
    {
        if (!(bool) env('USE_PATTERN_RECOGNITION', true)) {
            return [];
        }

        try {
            $symbol = $this->resolveSymbolForPatternDetection($marketHint);
            if ($symbol === null || !TimeframeCatalog::has($timeframeLabel)) {
                return [];
            }

            $candles = (new MarketDataManager())->getCandles($symbol, $timeframeLabel);
            if (count($candles) < 20) {
                return [];
            }

            $rows = array_map(static fn(Candle $c): array => $c->toLegacyRow(), $candles);

            $matches = (new PatternRecognitionEngine())->detectAboveConfidence($rows, 55.0);

            return array_map(static fn(PatternMatch $m): array => $m->toArray(), $matches);
        } catch (\Throwable $e) {
            error_log('[VisionController] Algorithmic pattern cross-check skipped: ' . $e->getMessage());
            return [];
        }
    }

    private function resolveSymbolForPatternDetection(string $hint): ?string
    {
        $normalizedHint = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $hint));
        if ($normalizedHint === '') {
            return null;
        }

        foreach (configured_markets() as $market) {
            $normalizedLabel  = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $market['label']));
            $normalizedSymbol = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $market['symbol']));
            if ($normalizedHint === $normalizedLabel || $normalizedHint === $normalizedSymbol) {
                return $market['symbol'];
            }
        }

        return null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        $value = trim((string) $value);
        return $value === '' ? null : (float) $value;
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('AI_VISION')) {
            return true;
        }

        $_SESSION['flash_error'] = 'AI Vision is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }

    /**
     * Tolerant JSON parser matching the pattern already used by
     * AiSummaryService/AiInsightService/AiBacktestSummaryService — strips
     * markdown code fences, validates the recommendation decision enum.
     */
    private function parseVisionResponse(string $text): ?array
    {
        $text = trim((string) preg_replace('/^```(?:json)?\s*/i', '', $text));
        $text = trim((string) preg_replace('/\s*```\s*$/i', '', $text));

        $data = json_decode($text, true);
        if (!is_array($data)) {
            error_log('[VisionController] Unparseable vision JSON: ' . mb_substr($text, 0, 300));
            return null;
        }

        if (isset($data['recommendation']['decision'])) {
            $decision = strtoupper(trim((string) $data['recommendation']['decision']));
            $data['recommendation']['decision'] = \in_array($decision, self::VALID_DECISIONS, true) ? $decision : 'NO_TRADE';
        }

        return $data;
    }
}
