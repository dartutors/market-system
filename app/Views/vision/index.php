<?php
use App\Vision\VisionTimeframeCatalog;
$buckets = VisionTimeframeCatalog::buckets();

/** Humanizes enum-ish values like 'insufficient_data' -> 'Insufficient data'. */
function vw_label(?string $value, string $fallback = 'No data'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    return ucfirst(str_replace('_', ' ', $value));
}
?>
<div class="page-header">
    <h2 class="page-title">AI Vision — Multi-Timeframe Workspace</h2>
    <p class="page-sub">Upload a chart screenshot per timeframe for a top-down AI read. Educational only — no real trading, and this system never executes trades.</p>
</div>

<!-- ── Multi-timeframe upload workspace ────────────────────────────────── -->
<div class="card card--accent">
    <form method="post" action="<?= e(url('vision/analyze')) ?>" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="form-group" style="margin-bottom:16px">
            <label for="vision-market-hint">Pair hint (optional, applies to every timeframe)</label>
            <input type="text" id="vision-market-hint" name="market_hint" class="form-select" placeholder="e.g. EUR/USD" style="max-width:240px" value="<?= e($pairHint ?? '') ?>">
        </div>

        <div class="vw-grid">
            <?php foreach ($timeframes as $label):
                $slotKey = strtolower($label);
                $bucket  = null;
                foreach ($buckets as $bucketName => $bucketLabels) {
                    if (in_array($label, $bucketLabels, true)) { $bucket = $bucketName; break; }
                }
            ?>
            <div class="vw-slot" data-slot="<?= e($slotKey) ?>" data-bucket="<?= e((string) $bucket) ?>" tabindex="0">
                <div class="vw-slot__hd">
                    <span class="vw-slot__label"><?= e($label) ?></span>
                    <span class="vw-slot__bucket vw-slot__bucket--<?= e((string) $bucket) ?>"><?= e(ucfirst((string) $bucket)) ?>-term</span>
                </div>
                <div class="vw-slot__zone">
                    <img data-role="preview" class="vw-slot__preview" alt="">
                    <span class="vw-slot__placeholder">Click, drag, or paste (Ctrl+V)</span>
                    <input type="file" data-role="input" name="chart_<?= e($slotKey) ?>" accept="image/png,image/jpeg,image/webp" class="vw-slot__input">
                    <button type="button" data-role="remove" class="vw-slot__btn vw-slot__btn--remove" title="Remove">&times;</button>
                    <button type="button" data-role="zoom" class="vw-slot__btn vw-slot__btn--zoom" title="Zoom">&#128269;</button>
                </div>
                <span data-role="filename" class="vw-slot__filename"></span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($risk_management_enabled): ?>
        <div class="vw-risk-fields">
            <div class="form-group">
                <label for="vision-balance">Account balance ($)</label>
                <input type="number" id="vision-balance" name="balance" min="0" step="100" value="1000" class="form-input">
            </div>
            <div class="form-group">
                <label for="vision-risk-pct">Risk this trade (%)</label>
                <input type="number" id="vision-risk-pct" name="risk_percent" min="0.1" max="10" step="0.1" value="1" class="form-input">
            </div>
            <div class="form-group">
                <label for="vision-entry-override">Entry price override (optional)</label>
                <input type="number" id="vision-entry-override" name="entry_price" step="0.00001" placeholder="auto from lowest chart" class="form-input">
            </div>
            <div class="form-group">
                <label for="vision-stop-override">Stop price override (optional)</label>
                <input type="number" id="vision-stop-override" name="stop_price" step="0.00001" placeholder="auto from chart/ATR" class="form-input">
            </div>
            <div class="form-group">
                <label for="vision-target-override">Target price override (optional)</label>
                <input type="number" id="vision-target-override" name="target_price" step="0.00001" placeholder="auto from chart" class="form-input">
            </div>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn--scan" style="margin-top:16px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            Run Top-Down Analysis
        </button>
        <p class="bt-note" style="margin-top:8px">Upload at least one timeframe. More timeframes (especially Monthly/Weekly/Daily) raise the analysis completeness score and the reliability of the final decision.</p>
    </form>
</div>

<?php if (isset($result)): ?>
<?php if (isset($result['error'])): ?>
    <div class="flash error"><?= e($result['error']) ?></div>
<?php else:
    $completeness = $result['completeness'];
    $topDown      = $result['top_down'];
    $decision     = $result['decision'];
    $synthesis    = $result['synthesis'];

    $decCls = match ($decision['decision']) {
        'BUY', 'SELL' => 'decision--valid',
        'WAIT'        => 'decision--improve',
        default       => 'decision--wait',
    };
    $decIcon = match ($decision['decision']) {
        'BUY'  => '↑',
        'SELL' => '↓',
        'WAIT' => '⏸',
        default => '✕',
    };
?>

    <?php if ($result['pair_warning']): ?>
    <div class="flash error" style="margin-top:20px"><?= e($result['pair_warning']) ?></div>
    <?php endif; ?>

    <!-- ── Analysis completeness (Phase 6) ──────────────────────────────── -->
    <div class="card abt-card">
        <div class="card__hd">
            <h3 class="card__title">Analysis Completeness</h3>
            <span class="card__count"><?= count($completeness['uploaded']) ?> / <?= count($timeframes) ?> timeframes</span>
        </div>
        <div class="vw-completeness-bar">
            <div class="vw-completeness-fill" style="width:<?= $completeness['percentage'] ?>%"></div>
        </div>
        <p class="abt-mc-sub"><?= $completeness['percentage'] ?>% weighted completeness (higher timeframes count more)</p>
        <?php if (!empty($completeness['warnings'])): ?>
        <ul class="bt-ai-reasons">
            <?php foreach ($completeness['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- ── Individual per-timeframe analysis (Phase 3) ──────────────────── -->
    <div class="card abt-card">
        <div class="card__hd"><h3 class="card__title">Individual Timeframe Analysis</h3></div>
        <div class="vw-slot-results">
            <?php foreach ($result['slots'] as $label => $slotResult): ?>
            <?php if (isset($slotResult['error'])): ?>
            <div class="vw-slot-result vw-slot-result--error">
                <strong><?= e($label) ?></strong> — <?= e($slotResult['error']) ?>
            </div>
            <?php else:
                $p = $slotResult['parsed'];
                $trend = (string) ($p['trend'] ?? 'neutral');
                $rec = $p['recommendation'] ?? [];
            ?>
            <div class="vw-slot-result">
                <div class="vw-slot-result__hd">
                    <strong><?= e($label) ?></strong>
                    <img src="<?= e(url('vision/image')) ?>?id=<?= (int) $slotResult['image_id'] ?>" alt="" class="vw-slot-result__thumb">
                    <span class="stat-block <?= $trend === 'bullish' ? 'stat-block--green' : ($trend === 'bearish' ? 'stat-block--red' : '') ?>" style="padding:4px 10px">
                        <span class="stat-block__val" style="font-size:0.85rem"><?= e(ucfirst($trend)) ?></span>
                    </span>
                    <span class="td--action"><?= e((string) ($rec['decision'] ?? 'NO_TRADE')) ?></span>
                    <span class="td--muted"><?= (int) ($rec['confidence'] ?? 0) ?>% confidence</span>
                </div>
                <?php if (!empty($slotResult['needs_confirmation'])): ?>
                <p class="abt-mc-sub" style="color:var(--yellow)">⚠ Detection confidence below 90% — please confirm this is <?= e((string) ($p['detected_pair'] ?? 'the correct pair')) ?> / <?= e($label) ?>.</p>
                <?php endif; ?>
                <?php if (!empty($rec['reasoning'])): ?><p class="abt-mc-sub"><?= e((string) $rec['reasoning']) ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Top-down analysis engine (Phase 4) ───────────────────────────── -->
    <div class="card abt-card">
        <div class="card__hd"><h3 class="card__title">Top-Down Analysis</h3></div>
        <div class="bt-stats-grid">
            <div class="stat-block <?= $topDown['overall_trend'] === 'bullish' ? 'stat-block--green' : ($topDown['overall_trend'] === 'bearish' ? 'stat-block--red' : '') ?>">
                <span class="stat-block__val"><?= e(ucfirst($topDown['overall_trend'])) ?></span>
                <span class="stat-block__lbl">Overall Trend</span>
            </div>
            <div class="stat-block">
                <span class="stat-block__val"><?= $topDown['overall_confidence'] ?>%</span>
                <span class="stat-block__lbl">Weighted Confidence</span>
            </div>
            <div class="stat-block">
                <span class="stat-block__val"><?= e(vw_label($topDown['alignment'])) ?></span>
                <span class="stat-block__lbl">Trend Alignment</span>
            </div>
            <div class="stat-block">
                <span class="stat-block__val"><?= e(vw_label($topDown['momentum'])) ?></span>
                <span class="stat-block__lbl">Momentum</span>
            </div>
            <div class="stat-block <?= $topDown['potential_reversal'] ? 'stat-block--red' : '' ?>">
                <span class="stat-block__val"><?= $topDown['potential_reversal'] ? 'Yes' : 'No' ?></span>
                <span class="stat-block__lbl">Potential Reversal</span>
            </div>
        </div>

        <div class="vw-buckets">
            <?php foreach (['long' => 'Long-Term', 'medium' => 'Medium-Term', 'short' => 'Short-Term'] as $key => $bucketLabel):
                $b = $topDown['buckets'][$key];
            ?>
            <div class="vw-bucket">
                <span class="vw-bucket__lbl"><?= e($bucketLabel) ?> (<?= e(implode(', ', $b['labels']) ?: 'no data') ?>)</span>
                <span class="vw-bucket__bias vw-bucket__bias--<?= e((string) ($b['bias'] ?? 'none')) ?>"><?= e($b['bias'] !== null ? ucfirst($b['bias']) : 'No data') ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($topDown['conflicts'])): ?>
        <p><strong>Conflicting timeframes:</strong></p>
        <ul class="bt-ai-reasons"><?php foreach ($topDown['conflicts'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!empty($topDown['supporting_timeframes'])): ?>
        <p><strong>Supporting timeframes:</strong> <?= e(implode(', ', $topDown['supporting_timeframes'])) ?></p>
        <?php endif; ?>
    </div>

    <!-- ══ MULTI-TIMEFRAME DECISION (Phase 5) ═══════════════════════════ -->
    <div class="card bt-ai-card">
        <div class="card__hd">
            <h3 class="card__title">Multi-Timeframe Decision</h3>
            <span class="badge badge--ai badge--ai-<?= e(strtolower($synthesis['provider'])) ?>"><?= e(ucfirst(str_replace('_', ' ', $synthesis['provider']))) ?></span>
        </div>

        <div class="bt-decision <?= e($decCls) ?>">
            <span class="bt-decision__icon"><?= $decIcon ?></span>
            <div class="bt-decision__body">
                <span class="bt-decision__label"><?= e($decision['decision']) ?></span>
                <span class="bt-decision__desc">Risk: <?= e(vw_label($decision['risk_level'])) ?> &middot; Trade quality: <?= e(vw_label($decision['trade_quality'])) ?></span>
            </div>
            <div class="bt-decision__conf">
                <span class="bt-decision__conf-label">Confidence</span>
                <div class="bt-conf-bar">
                    <div class="bt-conf-fill bt-conf-fill--<?= e(strtolower($decision['decision'])) ?>" style="width:<?= max(4, $decision['confidence']) ?>%"></div>
                </div>
                <span class="bt-decision__conf-pct"><?= $decision['confidence'] ?>%</span>
            </div>
        </div>

        <!-- ══ AI EXPLANATION PANEL (Phase 7) ══════════════════════════════ -->
        <?php foreach (explode("\n", (string) $synthesis['narrative']) as $line): if (trim($line) === '') continue; ?>
        <p class="bt-ai-summary"><?= e($line) ?></p>
        <?php endforeach; ?>

        <?php if (!empty($decision['why'])): ?>
        <ul class="bt-ai-reasons"><?php foreach ($decision['why'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <?php if (!empty($synthesis['risk_note'])): ?>
        <p class="bt-note"><?= e($synthesis['risk_note']) ?></p>
        <?php endif; ?>
    </div>

    <!-- ══ RISK MANAGEMENT FOR THIS SETUP ══════════════════════════════ -->
    <?php if ($result['risk_assessment'] !== null):
        $riskAssessment = $result['risk_assessment'];
    ?>
    <div class="page-header" style="margin-top:28px;margin-bottom:0">
        <h3 class="card__title">Risk Management For This Setup</h3>
        <p class="page-sub">Sized from the lowest analyzed timeframe's detected price and support/resistance levels — review and adjust before acting.</p>
    </div>
    <div style="margin-top:12px">
        <?php require app_path('Views/partials/risk-assessment-block.php'); ?>
    </div>
    <?php elseif (!empty($result['risk_assessment_message'])): ?>
    <div class="card" style="margin-top:20px">
        <div class="card__hd"><h3 class="card__title">Risk Management For This Setup</h3></div>
        <p class="stat-block__lbl"><?= e($result['risk_assessment_message']) ?></p>
    </div>
    <?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<!-- ── Zoom modal (shared by every slot) ────────────────────────────────── -->
<div id="vw-zoom-modal" class="vw-zoom-modal">
    <button type="button" data-role="zoom-close" class="vw-zoom-modal__close">&times;</button>
    <img id="vw-zoom-image" class="vw-zoom-modal__image" alt="">
</div>

<script src="<?= e(asset('js/vision-workspace.js')) ?>"></script>
