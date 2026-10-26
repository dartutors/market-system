<div class="page-header">
    <h2 class="page-title">Multi-Timeframe Confluence</h2>
    <p class="page-sub">Weighted trend alignment across Monthly → M5. Higher timeframes carry more weight. Educational only — no real trading.</p>
</div>

<!-- ── Run form ─────────────────────────────────────────────────────── -->
<div class="card card--accent">
    <form method="post" action="<?= e(url('multi-timeframe/analyze')) ?>" class="bt-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="bt-form__fields">
            <div class="form-group">
                <label for="mtf-symbol">Market pair</label>
                <select id="mtf-symbol" name="symbol" class="form-select">
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['symbol']) ?>"
                        <?= isset($selected) && $selected === $m['symbol'] ? 'selected' : '' ?>>
                        <?= e($m['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn--scan">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/>
                    <line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/>
                </svg>
                Analyze All Timeframes
            </button>
        </div>
    </form>
</div>

<?php if (isset($result)): ?>

    <?php if (isset($result['error'])): ?>
    <div class="flash error"><?= e($result['error']) ?></div>

    <?php else:
        $decision = $result['decision'];
        $analysis = $result['analysis'];
        $bias     = strtoupper($analysis->overallBias);
        $decCls   = match ($analysis->overallBias) {
            'bullish' => 'decision--valid',
            'bearish' => 'decision--valid',
            default   => 'decision--wait',
        };
        $decIcon = match ($analysis->overallBias) {
            'bullish' => '↑', 'bearish' => '↓', default => '—',
        };
        $recoCls = match ($decision->recommendation) {
            'BUY', 'SELL' => 'stat-block--green',
            'WATCH'       => '',
            default       => 'stat-block--red',
        };
    ?>

    <div class="bt-stats-grid">
        <div class="stat-block">
            <span class="stat-block__val"><?= e($result['label']) ?></span>
            <span class="stat-block__lbl">Market</span>
        </div>
        <div class="stat-block <?= $analysis->overallBias === 'bullish' ? 'stat-block--green' : ($analysis->overallBias === 'bearish' ? 'stat-block--red' : '') ?>">
            <span class="stat-block__val"><?= e($bias) ?></span>
            <span class="stat-block__lbl">Overall Bias</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $analysis->confidence ?>%</span>
            <span class="stat-block__lbl">Confidence</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= (int) $result['timeframes_used'] ?>/8</span>
            <span class="stat-block__lbl">Timeframes Used</span>
        </div>
        <div class="stat-block <?= e($recoCls) ?>">
            <span class="stat-block__val"><?= e($decision->recommendation) ?></span>
            <span class="stat-block__lbl">Recommendation (<?= $decision->recommendationConfidence ?>%)</span>
        </div>
    </div>

    <div class="card bt-ai-card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Confluence Read</h3>
        </div>
        <div class="bt-decision <?= e($decCls) ?>">
            <span class="bt-decision__icon"><?= $decIcon ?></span>
            <div class="bt-decision__body">
                <span class="bt-decision__label"><?= e($bias) ?></span>
                <span class="bt-decision__desc">
                    <?= count($analysis->conflicts) > 0
                        ? count($analysis->conflicts) . ' timeframe(s) conflict with the overall bias — see below.'
                        : 'No conflicting timeframes detected.' ?>
                </span>
            </div>
            <div class="bt-decision__conf">
                <span class="bt-decision__conf-label">Weighted Confidence</span>
                <div class="bt-conf-bar">
                    <div class="bt-conf-fill bt-conf-fill--<?= $analysis->overallBias === 'neutral' ? 'wait' : 'valid' ?>"
                         style="width:<?= max(4, $analysis->confidence) ?>%"></div>
                </div>
                <span class="bt-decision__conf-pct"><?= $analysis->confidence ?>%</span>
            </div>
        </div>

        <?php if (!empty($analysis->conflicts)): ?>
        <ul class="bt-ai-reasons">
            <?php foreach ($analysis->conflicts as $c): ?>
            <li><?= e($c) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- ── Pullback + entry confirmation ────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Pullback &amp; Entry Confirmation</h3>
        </div>

        <?php
            $pullbackHits = array_filter($decision->pullbacks, static fn($p) => $p->isPullback);
            $entryStateLabel = match ($decision->entry->state) {
                'confirmed'     => 'Confirmed',
                'pullback_only' => 'Pullback in progress',
                default         => 'No setup',
            };
        ?>

        <p><strong>Entry state:</strong> <?= e($entryStateLabel) ?></p>
        <p><?= e($decision->entry->reason) ?></p>

        <?php if (!empty($pullbackHits)): ?>
        <ul class="bt-ai-reasons">
            <?php foreach ($pullbackHits as $p): ?>
            <li><?= e($p->note) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="stat-block__lbl">No timeframes are currently in a recognizable pullback.</p>
        <?php endif; ?>
    </div>

    <!-- ── AI multi-timeframe summary ───────────────────────────────────── -->
    <div class="card bt-ai-card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">AI Multi-Timeframe Summary</h3>
        </div>
        <p><?= e($decision->aiSummary['narrative'] ?? '') ?></p>
        <?php if (!empty($decision->aiSummary['risk_note'])): ?>
        <p class="stat-block__lbl"><?= e($decision->aiSummary['risk_note']) ?></p>
        <?php endif; ?>
    </div>

    <!-- ── Per-timeframe breakdown ──────────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">Per-Timeframe Breakdown</h3>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Timeframe</th>
                        <th>Trend</th>
                        <th>Momentum</th>
                        <th>Status</th>
                        <th>Weight</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($analysis->breakdown as $label => $b):
                    $isPullback = ($decision->pullbacks[$label] ?? null)?->isPullback ?? false;
                    $rowCls = match (true) {
                        $isPullback              => 'tr--wait',
                        $b['status'] === 'aligned'     => 'tr--buy',
                        $b['status'] === 'conflicting' => 'tr--sell',
                        default                         => '',
                    };
                    $statusLabel = $isPullback ? 'Pullback' : ucfirst($b['status']);
                ?>
                <tr class="<?= e($rowCls) ?>">
                    <td class="td--nowrap"><strong><?= e($label) ?></strong></td>
                    <td class="td--action"><?= e(ucfirst($b['trend'])) ?></td>
                    <td><?= e(ucfirst($b['momentum'])) ?></td>
                    <td class="td--action"><?= e($statusLabel) ?></td>
                    <td class="td--mono td--muted"><?= round($b['weight'] * 100) ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
<?php endif; ?>
