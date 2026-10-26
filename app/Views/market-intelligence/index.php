<div class="page-header">
    <h2 class="page-title">Market Intelligence Engine</h2>
    <p class="page-sub">A richer overview of market conditions before drilling into individual instruments. Educational only — no real trading.</p>
</div>

<?php if (isset($result['error'])): ?>
<div class="flash error"><?= e($result['error']) ?></div>

<?php else:
    $snap = $result['snapshot'];
    $pairs = $snap->pairSnapshots;
?>

<!-- ══ CURRENCY STRENGTH METER + SECTOR (CURRENCY) HEATMAP ═══════ -->
<div class="db-row db-row--bottom">
    <div class="card">
        <div class="card__hd"><h3 class="card__title">Currency Strength Meter</h3></div>
        <?php if (!empty($snap->currencyStrength)): ?>
        <ul class="bt-ai-reasons">
            <?php foreach ($snap->currencyStrength as $currency => $score): ?>
            <li><strong><?= e($currency) ?></strong>: <?= $score >= 0 ? '+' : '' ?><?= $score ?>% avg return across configured pairs</li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="empty">No currency data available.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__hd"><h3 class="card__title">Sector Heatmap (by currency bloc)</h3></div>
        <p class="card__sub" style="margin-bottom:10px">This system trades currency pairs only — currency blocs stand in for equity-style sectors.</p>
        <div class="mi-heatmap">
            <?php foreach ($snap->currencyStrength as $currency => $score): ?>
            <div class="mi-heat-tile" style="background-color:<?= mi_heat_color($score, 0.5) ?>">
                <span class="mi-heat-tile__label"><?= e($currency) ?></span>
                <span class="mi-heat-tile__val"><?= $score >= 0 ? '+' : '' ?><?= $score ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ══ MARKET HEATMAP ════════════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd"><h3 class="card__title">Market Heatmap (by pair)</h3></div>
    <div class="mi-heatmap">
        <?php foreach ($pairs as $label => $p): ?>
        <div class="mi-heat-tile" style="background-color:<?= mi_heat_color($p->dailyReturnPct, 1.0) ?>">
            <span class="mi-heat-tile__label"><?= e($label) ?></span>
            <span class="mi-heat-tile__val"><?= $p->dailyReturnPct >= 0 ? '+' : '' ?><?= $p->dailyReturnPct ?>%</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ VOLATILITY INDEX ══════════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Volatility Index</h3>
        <span class="card__sub">ATR as % of price, per pair — a price-independent volatility read</span>
    </div>
    <div class="bt-stats-grid">
        <div class="stat-block <?= $snap->volatilityIndex['level'] === 'high' ? 'stat-block--red' : ($snap->volatilityIndex['level'] === 'low' ? 'stat-block--green' : '') ?>">
            <span class="stat-block__val"><?= $snap->volatilityIndex['overall_index'] ?>%</span>
            <span class="stat-block__lbl">Overall (<?= e(ucfirst($snap->volatilityIndex['level'])) ?>)</span>
        </div>
        <?php foreach ($snap->volatilityIndex['by_pair'] as $label => $v): ?>
        <div class="stat-block <?= $v['level'] === 'high' ? 'stat-block--red' : ($v['level'] === 'low' ? 'stat-block--green' : '') ?>">
            <span class="stat-block__val"><?= $v['atr_percent'] ?>%</span>
            <span class="stat-block__lbl"><?= e($label) ?> (<?= e(ucfirst($v['level'])) ?>)</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ SESSION ANALYSIS ══════════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Session Analysis</h3>
        <span class="card__sub">Average candle range (H1, last 60 days) as % of price, by trading session</span>
    </div>
    <?php $sa = $snap->sessionActivity; ?>
    <p>Current session: <strong><?= e(ucfirst(str_replace('_', ' ', $sa['current_session']))) ?></strong>
        <?php if ($sa['next_session'] !== null): ?>
        — next up: <strong><?= e(ucfirst(str_replace('_', ' ', $sa['next_session']))) ?></strong> in <?= (int) $sa['minutes_until_next'] ?> min
        <?php endif; ?>
    </p>
    <?php if ($sa['most_active_session'] !== null): ?>
    <p>Most active session across your configured pairs: <strong><?= e(ucfirst(str_replace('_', ' ', $sa['most_active_session']))) ?></strong></p>
    <?php endif; ?>
    <div class="tbl-wrap" style="margin-top:10px">
        <table class="tbl">
            <thead><tr><th>Session</th><?php foreach (array_keys($sa['by_pair']) as $label): ?><th><?= e($label) ?></th><?php endforeach; ?><th>Overall</th></tr></thead>
            <tbody>
            <?php foreach ($sa['by_session'] as $session => $overall): ?>
            <tr>
                <td><?= e(ucfirst(str_replace('_', ' ', $session))) ?></td>
                <?php foreach ($sa['by_pair'] as $label => $bySession): ?>
                <td><?= $bySession[$session] ?>%</td>
                <?php endforeach; ?>
                <td><strong><?= $overall ?>%</strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ SENTIMENT INDEX + NEWS IMPACT SCORING ═════════════════════ -->
<div class="db-row db-row--bottom" style="margin-top:20px">
    <div class="card">
        <div class="card__hd"><h3 class="card__title">Sentiment Index</h3></div>
        <?php $si = $snap->sentimentIndex; ?>
        <div class="bt-stats-grid" style="grid-template-columns:repeat(2,1fr)">
            <div class="stat-block <?= $si['index'] > 0 ? 'stat-block--green' : ($si['index'] < 0 ? 'stat-block--red' : '') ?>">
                <span class="stat-block__val"><?= $si['index'] > 0 ? '+' : '' ?><?= $si['index'] ?></span>
                <span class="stat-block__lbl"><?= e($si['label']) ?></span>
            </div>
            <div class="stat-block">
                <span class="stat-block__val"><?= e(ucfirst(str_replace('_', ' ', $si['bias']))) ?></span>
                <span class="stat-block__lbl">Bias (<?= $si['confidence'] ?>% confidence)</span>
            </div>
        </div>
        <p class="stat-block__lbl" style="margin-top:8px"><?= e($si['based_on']) ?></p>
    </div>

    <div class="card">
        <div class="card__hd"><h3 class="card__title">News Impact Scoring</h3></div>
        <?php $ni = $snap->newsImpact; ?>
        <div class="bt-stats-grid" style="grid-template-columns:repeat(2,1fr)">
            <div class="stat-block">
                <span class="stat-block__val"><?= $ni['average_impact'] ?></span>
                <span class="stat-block__lbl">Avg impact (last <?= count($ni['recent']) ?>)</span>
            </div>
            <div class="stat-block <?= $ni['high_impact_count'] > 0 ? 'stat-block--red' : '' ?>">
                <span class="stat-block__val"><?= (int) $ni['high_impact_count'] ?></span>
                <span class="stat-block__lbl">High-impact headlines</span>
            </div>
        </div>
        <?php if (!empty($ni['recent'])): ?>
        <ul class="bt-ai-reasons" style="margin-top:10px">
            <?php foreach (array_slice($ni['recent'], 0, 5) as $item): ?>
            <li><?= e($item['title']) ?> — impact <?= $item['impact_score'] ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<!-- ══ CORRELATION MATRIX ════════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Correlation Matrix</h3>
        <span class="card__sub">Pearson correlation of daily returns between configured pairs</span>
    </div>
    <?php if (!empty($snap->correlationMatrix)): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Pair A</th><th>Pair B</th><th>Coefficient</th><th>Read</th></tr></thead>
            <tbody>
            <?php foreach ($snap->correlationMatrix as $c): ?>
            <tr>
                <td><?= e($c['pair_a']) ?></td>
                <td><?= e($c['pair_b']) ?></td>
                <td class="td--mono"><?= $c['coefficient'] ?></td>
                <td><?= e(ucfirst($c['label'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">Not enough configured pairs to compute a correlation matrix.</p>
    <?php endif; ?>
</div>

<!-- ══ STRENGTH / WEAKNESS RANKING ═══════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Strength &amp; Weakness Ranking</h3>
        <span class="card__sub">ADX trend-strength magnitude, signed by direction — strongest at top, weakest at bottom</span>
    </div>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Rank</th><th>Pair</th><th>Trend</th><th>ADX14</th><th>Signed score</th></tr></thead>
            <tbody>
            <?php foreach ($snap->strengthRanking as $i => $r):
                $rowCls = match ($r['trend']) { 'bullish' => 'tr--buy', 'bearish' => 'tr--sell', default => '' };
            ?>
            <tr class="<?= e($rowCls) ?>">
                <td><?= $i + 1 ?></td>
                <td><span class="badge badge--market"><?= e($r['label']) ?></span></td>
                <td class="td--action"><?= e(ucfirst($r['trend'])) ?></td>
                <td class="td--mono"><?= $r['adx14'] ?></td>
                <td class="td--mono"><?= $r['signed_score'] >= 0 ? '+' : '' ?><?= $r['signed_score'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="stat-block__lbl" style="margin-top:16px">Generated <?= e($snap->generatedAt->format('Y-m-d H:i:s')) ?> EAT. Refresh the page to recompute from the latest live data.</p>

<?php endif; ?>

<style>
.mi-heatmap { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
.mi-heat-tile {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 16px 8px; border-radius: 8px; min-height: 70px;
    color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.4);
}
.mi-heat-tile__label { font-weight: 600; font-size: 0.95rem; }
.mi-heat-tile__val { font-size: 0.85rem; margin-top: 4px; }
</style>
