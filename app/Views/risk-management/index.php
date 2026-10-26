<div class="page-header">
    <h2 class="page-title">Risk Management</h2>
    <p class="page-sub">Position sizing, stop-loss / take-profit / risk-reward calculators, and daily/weekly/drawdown/exposure guardrails — before you enter a trade. Educational only — no real trading.</p>
</div>

<!-- ── Run form ─────────────────────────────────────────────────────── -->
<div class="card card--accent">
    <form method="post" action="<?= e(url('risk-management/assess')) ?>" class="bt-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="bt-form__fields">
            <div class="form-group">
                <label for="rm-market">Market pair</label>
                <select id="rm-market" name="market_label" class="form-select">
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['label']) ?>"
                        <?= isset($selected) && $selected === $m['label'] ? 'selected' : '' ?>>
                        <?= e($m['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="rm-direction">Direction</label>
                <select id="rm-direction" name="direction" class="form-select">
                    <option value="BUY">Buy</option>
                    <option value="SELL">Sell</option>
                </select>
            </div>

            <div class="form-group">
                <label for="rm-balance">Account balance ($)</label>
                <input type="number" id="rm-balance" name="balance" min="0" step="100" value="1000" class="form-input">
            </div>

            <div class="form-group">
                <label for="rm-risk-pct">Risk this trade (%)</label>
                <input type="number" id="rm-risk-pct" name="risk_percent" min="0.1" max="10" step="0.1" value="1" class="form-input">
            </div>

            <div class="form-group">
                <label for="rm-entry">Entry price</label>
                <input type="number" id="rm-entry" name="entry_price" step="0.00001" placeholder="required" class="form-input">
            </div>

            <div class="form-group">
                <label for="rm-stop-price">Stop price (optional)</label>
                <input type="number" id="rm-stop-price" name="stop_price" step="0.00001" placeholder="auto from ATR" class="form-input">
            </div>

            <div class="form-group">
                <label for="rm-stop-pips">Stop distance in pips (optional)</label>
                <input type="number" id="rm-stop-pips" name="stop_pips" min="0" step="0.1" placeholder="auto from ATR" class="form-input">
            </div>

            <div class="form-group">
                <label for="rm-target">Target price (optional)</label>
                <input type="number" id="rm-target" name="target_price" step="0.00001" placeholder="for risk/reward check" class="form-input">
            </div>

            <button type="submit" class="btn btn--scan">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s-8-4.5-8-11.8V5l8-3 8 3v5.2C20 17.5 12 22 12 22z"/>
                </svg>
                Assess Risk
            </button>
        </div>
        <p class="stat-block__lbl" style="margin-top:8px">Leave both stop fields blank to auto-suggest a stop from live ATR volatility for the selected pair.</p>
    </form>
</div>

<?php if (isset($result)): ?>

    <?php if (isset($result['error'])): ?>
    <div class="flash error"><?= e($result['error']) ?></div>

    <?php else:
        $riskAssessment = $result['assessment'];
        require app_path('Views/partials/risk-assessment-block.php');
    endif; ?>
<?php endif; ?>
