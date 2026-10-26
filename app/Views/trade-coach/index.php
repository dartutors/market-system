<div class="page-header">
    <h2 class="page-title">AI Trade Coach</h2>
    <p class="page-sub">Explains the latest recommendation for a market in plain language — why it was made, the risk involved, and what to watch for. Educational only — no real trading.</p>
</div>

<!-- ── Run form ─────────────────────────────────────────────────────── -->
<div class="card card--accent">
    <form method="post" action="<?= e(url('trade-coach/explain')) ?>" class="bt-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="bt-form__fields">
            <div class="form-group">
                <label for="coach-market">Market pair</label>
                <select id="coach-market" name="market_label" class="form-select">
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['label']) ?>"
                        <?= isset($selected) && $selected === $m['label'] ? 'selected' : '' ?>>
                        <?= e($m['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="coach-mode">Explanation style</label>
                <select id="coach-mode" name="mode" class="form-select">
                    <option value="beginner" <?= $mode === 'beginner' ? 'selected' : '' ?>>Beginner</option>
                    <option value="professional" <?= $mode === 'professional' ? 'selected' : '' ?>>Professional</option>
                </select>
            </div>

            <button type="submit" class="btn btn--scan">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2a5 5 0 0 0-5 5v2a5 5 0 0 0 10 0V7a5 5 0 0 0-5-5z"/>
                    <path d="M8 14v2a4 4 0 0 0 8 0v-2"/><line x1="12" y1="20" x2="12" y2="22"/>
                </svg>
                Explain This Decision
            </button>
        </div>
    </form>
</div>

<?php if (isset($result)): ?>

    <?php if (isset($result['error'])): ?>
    <div class="flash error"><?= e($result['error']) ?></div>

    <?php else:
        $x       = $result['explanation'];
        $decUp   = strtoupper($x->decision);
        $decCls  = match ($x->decision) {
            'BUY', 'SELL' => 'decision--valid',
            default       => 'decision--wait',
        };
        $decIcon = match ($x->decision) {
            'BUY' => '↑', 'SELL' => '↓', default => '—',
        };
        $riskCls = match ($x->riskLevel) {
            'high'  => 'stat-block--red',
            'low'   => 'stat-block--green',
            default => '',
        };
    ?>

    <div class="bt-stats-grid">
        <div class="stat-block">
            <span class="stat-block__val"><?= e($x->marketLabel) ?></span>
            <span class="stat-block__lbl">Market</span>
        </div>
        <div class="stat-block <?= $x->decision === 'BUY' || $x->decision === 'SELL' ? 'stat-block--green' : '' ?>">
            <span class="stat-block__val"><?= e($decUp) ?></span>
            <span class="stat-block__lbl">Decision</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $x->confidence ?>%</span>
            <span class="stat-block__lbl">Confidence</span>
        </div>
        <div class="stat-block <?= e($riskCls) ?>">
            <span class="stat-block__val"><?= e(ucfirst($x->riskLevel)) ?></span>
            <span class="stat-block__lbl">Risk Level</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= e(ucfirst($x->mode)) ?></span>
            <span class="stat-block__lbl">Style</span>
        </div>
    </div>

    <!-- ── Headline ──────────────────────────────────────────────────────── -->
    <div class="card bt-ai-card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Why This Recommendation</h3>
        </div>
        <div class="bt-decision <?= e($decCls) ?>">
            <span class="bt-decision__icon"><?= $decIcon ?></span>
            <div class="bt-decision__body">
                <span class="bt-decision__label"><?= e($decUp) ?></span>
                <span class="bt-decision__desc"><?= e($x->headline) ?></span>
            </div>
        </div>

        <?php if (!empty($x->reasoning)): ?>
        <p class="stat-block__lbl" style="margin-top:14px">Based on:</p>
        <ul class="bt-ai-reasons">
            <?php foreach ($x->reasoning as $r): ?>
            <li><?= e($r) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!empty($x->conflicts)): ?>
        <p class="stat-block__lbl" style="margin-top:14px">Conflicting signals:</p>
        <ul class="bt-ai-reasons">
            <?php foreach ($x->conflicts as $c): ?>
            <li><?= e($c) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!empty($result['decision_age'])): ?>
        <p class="stat-block__lbl" style="margin-top:14px">Decision recorded: <?= e($result['decision_age']) ?></p>
        <?php endif; ?>
    </div>

    <!-- ── Risk explanation ─────────────────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Risk Explanation</h3>
        </div>
        <p><?= e($x->riskExplanation) ?></p>
    </div>

    <!-- ── AI narrative (optional) ──────────────────────────────────────── -->
    <?php if ($x->aiNarrative !== null): ?>
    <div class="card bt-ai-card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">AI Coach Narrative</h3>
        </div>
        <p><?= e($x->aiNarrative['narrative']) ?></p>
        <?php if (!empty($x->aiNarrative['risk_note'])): ?>
        <p class="stat-block__lbl"><?= e($x->aiNarrative['risk_note']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Educational tips ─────────────────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Educational Tips</h3>
        </div>
        <ul class="bt-ai-reasons">
            <?php foreach ($x->educationalTips as $tip): ?>
            <li><?= e($tip) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- ── Common mistakes ───────────────────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Common Mistakes to Avoid</h3>
        </div>
        <ul class="bt-ai-reasons">
            <?php foreach ($x->commonMistakes as $mistake): ?>
            <li><?= e($mistake) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- ── Trading psychology ───────────────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Trading Psychology</h3>
        </div>
        <p><?= e($x->psychologyGuidance) ?></p>
    </div>

    <?php endif; ?>
<?php endif; ?>
