<?php
/**
 * Shared risk-assessment render block — expects $riskAssessment
 * (App\Risk\RiskAssessment) to be set by the including view. Used by both
 * risk-management/index.php (its own on-demand calculator) and
 * vision/index.php (risk guidance for an AI Vision trade recommendation).
 */
$ps = $riskAssessment->positionSizing;
$sl = $riskAssessment->stopLoss;
$rr = $riskAssessment->riskReward;
$dl = $riskAssessment->dailyLimit;
$wl = $riskAssessment->weeklyLimit;
$dd = $riskAssessment->drawdown;
$ex = $riskAssessment->exposure;
$guidanceCls = match (true) {
    $dd['triggered'] || $dl['exceeded'] || $wl['exceeded'] => 'decision--wait',
    !empty($ex['warnings']) || !empty($riskAssessment->correlationWarnings) => 'decision--wait',
    $rr !== null && !$rr['meets_minimum'] => 'decision--wait',
    default => 'decision--valid',
};
?>
<div class="bt-stats-grid">
    <div class="stat-block">
        <span class="stat-block__val"><?= e($riskAssessment->marketLabel) ?></span>
        <span class="stat-block__lbl">Market</span>
    </div>
    <div class="stat-block <?= $riskAssessment->direction === 'BUY' ? 'stat-block--green' : 'stat-block--red' ?>">
        <span class="stat-block__val"><?= e($riskAssessment->direction) ?></span>
        <span class="stat-block__lbl">Direction</span>
    </div>
    <div class="stat-block">
        <span class="stat-block__val"><?= $ps['lot_size'] ?></span>
        <span class="stat-block__lbl">Standard Lots</span>
    </div>
    <div class="stat-block">
        <span class="stat-block__val">$<?= $ps['risk_amount'] ?></span>
        <span class="stat-block__lbl">Risk Amount</span>
    </div>
    <div class="stat-block">
        <span class="stat-block__val"><?= $sl['stop_pips'] ?> pips</span>
        <span class="stat-block__lbl">Stop Distance</span>
    </div>
</div>

<!-- ── Overall guidance ──────────────────────────────────────────────── -->
<div class="card bt-ai-card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Guidance Before You Enter</h3>
    </div>
    <div class="bt-decision <?= e($guidanceCls) ?>">
        <span class="bt-decision__icon"><?= $guidanceCls === 'decision--valid' ? '✓' : '!' ?></span>
        <div class="bt-decision__body">
            <span class="bt-decision__desc"><?= e($riskAssessment->overallGuidance) ?></span>
        </div>
    </div>
</div>

<!-- ── Position sizing ──────────────────────────────────────────────── -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Position Sizing</h3>
    </div>
    <ul class="bt-ai-reasons">
        <li>Risk amount: $<?= $ps['risk_amount'] ?> (<?= e($ps['pair']) ?>, pip value $<?= $ps['pip_value'] ?>/lot)</li>
        <li>Standard lots: <?= $ps['lot_size'] ?> &mdash; mini lots: <?= $ps['mini_lots'] ?> &mdash; micro lots: <?= $ps['micro_lots'] ?></li>
        <li>Entry: <?= $riskAssessment->entryPrice ?> &mdash; Stop: <?= $sl['stop_price'] ?> (<?= $sl['stop_pips'] ?> pips / $<?= $ps['stop_loss_cost'] ?> at risk)</li>
    </ul>
    <p class="stat-block__lbl"><?= e($ps['note']) ?></p>
</div>

<!-- ── Take-profit levels + risk/reward ─────────────────────────────── -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Take-Profit Levels &amp; Risk/Reward</h3>
    </div>
    <ul class="bt-ai-reasons">
        <?php foreach ($riskAssessment->takeProfitLevels as $rLabel => $price): ?>
        <li><?= e($rLabel) ?> target: <?= $price ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($rr !== null): ?>
    <p style="margin-top:10px"><strong>Your target:</strong> <?= e($rr['verdict']) ?></p>
    <?php else: ?>
    <p class="stat-block__lbl" style="margin-top:10px">Provide a target price to check its risk/reward ratio.</p>
    <?php endif; ?>
</div>

<!-- ── Daily / weekly risk limits ────────────────────────────────────── -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Daily &amp; Weekly Risk Limits</h3>
    </div>
    <ul class="bt-ai-reasons">
        <li>Today: <?= $dl['trades_today'] ?> trade(s) already taken; adding this one projects to roughly <?= $dl['projected_daily_risk_percent'] ?>% of account risked today (limit <?= $dl['max_daily_risk_percent'] ?>%).</li>
        <li>This week (<?= e($wl['week_start']) ?> to <?= e($wl['week_end']) ?>): <?= $wl['trades_this_week'] ?> trade(s) already taken; adding this one projects to roughly <?= $wl['projected_weekly_risk_percent'] ?>% risked this week (limit <?= $wl['max_weekly_risk_percent'] ?>%).</li>
    </ul>
    <p class="stat-block__lbl">Projections assume similar risk sizing on prior trades taken today/this week.</p>
</div>

<!-- ── Drawdown protection ───────────────────────────────────────────── -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Drawdown Protection</h3>
    </div>
    <p><?= e($dd['message']) ?></p>
</div>

<!-- ── Exposure + correlation ────────────────────────────────────────── -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Exposure &amp; Correlation</h3>
    </div>
    <p><?= (int) $ex['open_position_count'] ?> open position(s) currently tracked (signals awaiting an outcome).</p>

    <?php if (!empty($ex['warnings']) || !empty($riskAssessment->correlationWarnings)): ?>
    <ul class="bt-ai-reasons">
        <?php foreach ($ex['warnings'] as $w): ?>
        <li><?= e($w) ?></li>
        <?php endforeach; ?>
        <?php foreach ($riskAssessment->correlationWarnings as $w): ?>
        <li><?= e($w) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="stat-block__lbl">No exposure or correlation warnings against your open positions.</p>
    <?php endif; ?>
</div>
