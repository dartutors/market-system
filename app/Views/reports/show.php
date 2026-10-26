<?php
$fromStr = $from->format('Y-m-d');
$toStr   = $to->format('Y-m-d');
$hasDateFilter = !in_array($slug, ['system-health', 'currency-strength'], true);
$exportQs = 'from=' . urlencode($fromStr) . '&to=' . urlencode($toStr) . '&export=';
?>
<div class="page-header">
    <h2 class="page-title"><?= e($label) ?></h2>
    <p class="page-sub"><a href="<?= e(url('reports')) ?>">← All reports</a></p>
</div>

<div class="card rpt-toolbar">
    <?php if ($hasDateFilter): ?>
    <form method="get" class="rpt-filter-form">
        <div class="form-group"><label>From</label><input type="date" name="from" value="<?= e($fromStr) ?>" class="form-input"></div>
        <div class="form-group"><label>To</label><input type="date" name="to" value="<?= e($toStr) ?>" class="form-input"></div>
        <button type="submit" class="btn btn--scan">Apply</button>
    </form>
    <?php else: ?>
    <div></div>
    <?php endif; ?>
    <div class="rpt-export">
        <a href="?<?= e($exportQs) ?>csv" class="wl-action">⭳ CSV</a>
        <a href="?<?= e($exportQs) ?>excel" class="wl-action">⭳ Excel</a>
        <a href="?<?= e($exportQs) ?>pdf" class="wl-action">⭳ PDF</a>
        <button type="button" class="wl-action" onclick="window.print()">🖨 Print</button>
    </div>
</div>

<?php if (!empty($report['unavailable']) && isset($error)): ?>
<div class="card">
    <p class="empty"><?= e($error) ?></p>
</div>

<?php elseif ($slug === 'trading-performance'): $r = $report; ?>
<div class="card">
    <div class="bt-stats-grid">
        <div class="stat-block"><span class="stat-block__val"><?= $r['total'] ?></span><span class="stat-block__lbl">Total Signals</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $r['buy_count'] ?></span><span class="stat-block__lbl">Buy Signals</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= $r['sell_count'] ?></span><span class="stat-block__lbl">Sell Signals</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['wait_count'] ?></span><span class="stat-block__lbl">Wait Decisions</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $r['win_rate'] ?? '—' ?><?= $r['win_rate'] !== null ? '%' : '' ?></span><span class="stat-block__lbl">Win Rate</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= $r['loss_rate'] ?? '—' ?><?= $r['loss_rate'] !== null ? '%' : '' ?></span><span class="stat-block__lbl">Loss Rate</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['avg_confidence'] ?? '—' ?><?= $r['avg_confidence'] !== null ? '%' : '' ?></span><span class="stat-block__lbl">Average Confidence</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['avg_risk_reward'] ?? '—' ?><?= $r['avg_risk_reward'] !== null ? 'R' : '' ?></span><span class="stat-block__lbl">Average Risk/Reward</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['strategy_accuracy'] ?? '—' ?><?= $r['strategy_accuracy'] !== null ? '%' : '' ?></span><span class="stat-block__lbl">Strategy Accuracy</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= e((string) ($r['best_pair'] ?? '—')) ?></span><span class="stat-block__lbl">Best Performing Pair</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= e((string) ($r['worst_pair'] ?? '—')) ?></span><span class="stat-block__lbl">Worst Performing Pair</span></div>
    </div>
    <p class="card__sub" style="margin:16px 0 8px">Daily signal volume</p>
    <?= rpt_bars($r['daily'], 'day', 'total') ?>
    <div class="tbl-wrap" style="margin-top:14px">
        <table class="tbl"><thead><tr><th>Day</th><th>Signals</th><th>Wins</th><th>Losses</th></tr></thead><tbody>
        <?php foreach (array_reverse($r['daily']) as $d): ?>
            <tr><td><?= e((string) $d['day']) ?></td><td><?= (int) $d['total'] ?></td><td class="u-mo-up"><?= (int) $d['wins'] ?></td><td class="u-mo-down"><?= (int) $d['losses'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
</div>

<?php elseif ($slug === 'ai-usage'): $r = $report; ?>
<div class="card">
    <div class="bt-stats-grid">
        <div class="stat-block"><span class="stat-block__val"><?= $r['total_calls'] ?></span><span class="stat-block__lbl">Total AI Calls</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= number_format($r['total_tokens']) ?></span><span class="stat-block__lbl">Token Consumption</span></div>
        <div class="stat-block"><span class="stat-block__val">$<?= number_format($r['total_cost'], 4) ?></span><span class="stat-block__lbl">Estimated Cost</span></div>
        <?php foreach ($r['by_provider'] as $p): ?>
        <div class="stat-block"><span class="stat-block__val"><?= (int) $p['calls'] ?></span><span class="stat-block__lbl"><?= e(ucfirst((string) $p['provider'])) ?> Calls</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= round((float) $p['avg_latency_ms']) ?>ms</span><span class="stat-block__lbl"><?= e(ucfirst((string) $p['provider'])) ?> Avg Response Time</span></div>
        <?php endforeach; ?>
    </div>
    <p class="card__sub" style="margin:16px 0 8px">Daily cost (USD)</p>
    <?= rpt_bars($r['daily'], 'day', 'cost_usd', '#22c55e') ?>
    <div class="tbl-wrap" style="margin-top:14px">
        <table class="tbl"><thead><tr><th>Service</th><th>Calls</th><th>Cost (USD)</th></tr></thead><tbody>
        <?php foreach ($r['by_service'] as $s): ?>
            <tr><td><?= e((string) $s['service_name']) ?></td><td><?= (int) $s['calls'] ?></td><td class="td--mono">$<?= number_format((float) $s['cost_usd'], 4) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <p class="card__sub" style="margin-top:12px">Cache Efficiency is not shown: ai_provider_logs only records calls that reached a provider, so a cache hit never produces a row here — there's no real hit/miss ratio to compute.</p>
</div>

<?php elseif ($slug === 'system-health'): $r = $report;
    $statusOrder = ['database'=>'Database','ai_apis'=>'AI APIs','news'=>'RSS / News','market_data'=>'Market Data','calendar'=>'Calendar Updates','backtesting'=>'Backtesting','vision'=>'Vision Module','cron'=>'Cron Jobs'];
    $healthyCount = count(array_filter($r, static fn(array $c): bool => $c['status'] === 'healthy'));
?>
<div class="card">
    <div class="bt-stats-grid" style="grid-template-columns:repeat(3,1fr)">
        <div class="stat-block <?= $healthyCount === count($r) ? 'stat-block--green' : '' ?>"><span class="stat-block__val"><?= $healthyCount ?>/<?= count($r) ?></span><span class="stat-block__lbl">Components Healthy</span></div>
    </div>
    <div class="tbl-wrap" style="margin-top:16px">
        <table class="tbl"><thead><tr><th>Component</th><th>Status</th><th>Detail</th></tr></thead><tbody>
        <?php foreach ($statusOrder as $key => $displayLabel):
            $c = $r[$key];
            $badgeCls = match ($c['status']) { 'healthy' => 'badge--bullish', 'degraded', 'stale' => 'badge--neutral', 'down' => 'badge--bearish', default => 'badge--market' };
        ?>
            <tr><td><?= e($displayLabel) ?></td><td><span class="badge <?= e($badgeCls) ?>"><?= e(strtoupper($c['status'])) ?></span></td><td class="u-muted"><?= e($c['detail']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <p class="card__sub" style="margin-top:12px">Status thresholds are simple heuristics (e.g. "stale" after 24h of silence) disclosed here, not an SLA. Cron Jobs has no database tracking table anywhere in this schema — its status is read from cron log file timestamps instead.</p>
</div>

<?php elseif ($slug === 'scanner'): $r = $report; ?>
<div class="card">
    <div class="bt-stats-grid">
        <div class="stat-block"><span class="stat-block__val"><?= $r['scans_executed'] ?></span><span class="stat-block__lbl">Scans Executed</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['signals_generated'] ?></span><span class="stat-block__lbl">Signals Generated</span></div>
        <?php foreach ($r['scan_status'] as $s): ?>
        <div class="stat-block"><span class="stat-block__val"><?= (int) $s['total'] ?></span><span class="stat-block__lbl">Scans — <?= e(ucfirst((string) $s['run_status'])) ?></span></div>
        <?php endforeach; ?>
    </div>
    <div class="db-row db-row--bottom" style="margin-top:16px">
        <div>
            <p class="card__sub" style="margin-bottom:8px">Strongest Signals</p>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pair</th><th>Action</th><th>Conf.</th></tr></thead><tbody>
            <?php foreach ($r['strongest_signals'] as $s): ?><tr><td><?= e((string) $s['market_label']) ?></td><td><?= e((string) $s['action']) ?></td><td><?= (int) $s['confidence'] ?>%</td></tr><?php endforeach; ?>
            <?php if (empty($r['strongest_signals'])): ?><tr><td colspan="3" class="empty">None in range.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <div>
            <p class="card__sub" style="margin-bottom:8px">Weakest Signals</p>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pair</th><th>Action</th><th>Conf.</th></tr></thead><tbody>
            <?php foreach ($r['weakest_signals'] as $s): ?><tr><td><?= e((string) $s['market_label']) ?></td><td><?= e((string) $s['action']) ?></td><td><?= (int) $s['confidence'] ?>%</td></tr><?php endforeach; ?>
            <?php if (empty($r['weakest_signals'])): ?><tr><td colspan="3" class="empty">None in range.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
    </div>
    <p class="card__sub" style="margin:16px 0 8px">Pair Rankings</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pair</th><th>Signals</th><th>Wins</th><th>Avg Confidence</th></tr></thead><tbody>
    <?php foreach ($r['pair_rankings'] as $p): ?>
        <tr><td><?= e((string) $p['market_label']) ?></td><td><?= (int) $p['total'] ?></td><td><?= (int) $p['wins'] ?></td><td><?= round((float) $p['avg_confidence'], 1) ?>%</td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="card__sub" style="margin-top:12px">Technical Indicator Statistics is not shown: neither scan_runs nor any backtest table stores per-scan indicator readings.</p>
</div>

<?php elseif ($slug === 'news'): $r = $report; ?>
<div class="card">
    <div class="bt-stats-grid">
        <div class="stat-block"><span class="stat-block__val"><?= $r['articles_processed'] ?></span><span class="stat-block__lbl">Articles Processed</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $r['by_bias']['bullish'] ?></span><span class="stat-block__lbl">Bullish News</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= $r['by_bias']['bearish'] ?></span><span class="stat-block__lbl">Bearish News</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['by_bias']['neutral'] ?></span><span class="stat-block__lbl">Neutral News</span></div>
    </div>
    <p class="card__sub" style="margin:16px 0 8px">Source Performance</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Source</th><th>Articles</th><th>Avg Impact Score</th></tr></thead><tbody>
    <?php foreach ($r['by_source'] as $s): ?>
        <tr><td><?= e((string) $s['source']) ?></td><td><?= (int) $s['total'] ?></td><td><?= round((float) $s['avg_impact'], 1) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="card__sub" style="margin:16px 0 8px">AI Summary History</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>When</th><th>Provider</th><th>Bias</th><th>Confidence</th></tr></thead><tbody>
    <?php foreach ($r['ai_summary_history'] as $s): ?>
        <tr><td class="td--mono td--muted"><?= e(fmt_datetime((string) $s['created_at'])) ?></td><td><?= e((string) $s['provider']) ?></td><td><?= e((string) $s['market_bias']) ?></td><td><?= round((float) $s['confidence'], 1) ?>%</td></tr>
    <?php endforeach; ?>
    <?php if (empty($r['ai_summary_history'])): ?><tr><td colspan="4" class="empty">None in range.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<?php elseif ($slug === 'calendar'): $r = $report; ?>
<div class="card">
    <div class="bt-stats-grid">
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= $r['by_impact']['high'] ?></span><span class="stat-block__lbl">High Impact Events</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['by_impact']['medium'] ?></span><span class="stat-block__lbl">Medium Impact Events</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= count($r['upcoming_events']) ?></span><span class="stat-block__lbl">Upcoming Events (14d)</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= count($r['past_events']) ?></span><span class="stat-block__lbl">Past Events (range)</span></div>
        <div class="stat-block <?= !empty($r['blocking_sessions']) ? 'stat-block--red' : '' ?>"><span class="stat-block__val"><?= count($r['blocking_sessions']) ?></span><span class="stat-block__lbl">Currently Blocked Sessions</span></div>
    </div>
    <p class="card__sub" style="margin:16px 0 8px">Currency Breakdown</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Currency</th><th>Events</th></tr></thead><tbody>
    <?php foreach ($r['by_currency'] as $c): ?><tr><td class="td--ccy"><?= e((string) $c['currency']) ?></td><td><?= (int) $c['total'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="card__sub" style="margin:16px 0 8px">Past Events</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Event</th><th>Currency</th><th>Impact</th><th>Scheduled</th></tr></thead><tbody>
    <?php foreach (array_slice($r['past_events'], 0, 30) as $ev): ?>
        <tr><td><?= e((string) $ev['title']) ?></td><td class="td--ccy"><?= e((string) $ev['currency']) ?></td><td><span class="badge badge--<?= e(strtolower((string) $ev['impact'])) ?>"><?= e(strtoupper((string) $ev['impact'])) ?></span></td><td class="td--mono td--muted"><?= e(fmt_datetime((string) $ev['scheduled_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($r['past_events'])): ?><tr><td colspan="4" class="empty">None in range.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="card__sub" style="margin-top:12px">Historical Calendar Impact is not shown: calendar_events has no actual/outcome column, so there's no real "what happened to price" data to report.</p>
</div>

<?php elseif ($slug === 'currency-strength'): $r = $report; ?>
<div class="card">
    <?php if (empty($r['current'])): ?>
        <p class="empty">Currency strength data is unavailable right now.</p>
    <?php else: ?>
    <p class="card__sub" style="margin-bottom:10px">Current Ranking</p>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Rank</th><th>Currency</th><th>Strength</th></tr></thead><tbody>
    <?php $rank = 1; foreach ($r['current'] as $ccy => $score): ?>
        <tr><td>#<?= $rank++ ?></td><td class="td--ccy"><?= e($ccy) ?></td><td class="<?= $score >= 0 ? 'u-mo-up' : 'u-mo-down' ?>"><?= $score >= 0 ? '+' : '' ?><?= number_format($score, 2) ?>%</td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="card__sub" style="margin:18px 0 8px">History <?= $r['collecting_since'] ? '(collecting since ' . e(fmt_datetime((string) $r['collecting_since'])) . ')' : '(not yet collected)' ?></p>
    <?php foreach ($r['history'] as $ccy => $points): if (count($points) < 2) continue; ?>
        <p class="u-muted" style="font-size:0.78rem;margin-bottom:4px"><?= e($ccy) ?></p>
        <?= rpt_bars($points, 'captured_at', 'score', $points[count($points)-1]['score'] >= 0 ? '#22c55e' : '#ef4444') ?>
    <?php endforeach; ?>
    <p class="card__sub" style="margin-top:12px">This app never persisted currency-strength readings before this report shipped — history starts from that point and grows with real traffic, rather than showing a fabricated deep trend.</p>
    <?php endif; ?>
</div>

<?php elseif ($slug === 'ai-vision'): $r = $report; ?>
<div class="card">
    <div class="bt-stats-grid">
        <div class="stat-block"><span class="stat-block__val"><?= $r['total_images'] ?></span><span class="stat-block__lbl">Total Images Analysed</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['multi_timeframe_sessions'] ?></span><span class="stat-block__lbl">Multi-Timeframe Sessions</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['avg_confidence'] ?? '—' ?><?= $r['avg_confidence'] !== null ? '%' : '' ?></span><span class="stat-block__lbl">Average AI Confidence</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['cross_check_rate'] ?? '—' ?><?= $r['cross_check_rate'] !== null ? '%' : '' ?></span><span class="stat-block__lbl">Algorithmic Cross-Check Rate</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $r['decision_counts']['BUY'] ?></span><span class="stat-block__lbl">Buy Recommendations</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= $r['decision_counts']['SELL'] ?></span><span class="stat-block__lbl">Sell Recommendations</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= $r['decision_counts']['WAIT'] ?></span><span class="stat-block__lbl">Wait Recommendations</span></div>
    </div>
    <div class="db-row db-row--bottom" style="margin-top:16px">
        <div>
            <p class="card__sub" style="margin-bottom:8px">Most Common Patterns</p>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pattern</th><th>Occurrences</th></tr></thead><tbody>
            <?php foreach ($r['most_common_patterns'] as $pattern => $count): ?><tr><td><?= e((string) $pattern) ?></td><td><?= $count ?></td></tr><?php endforeach; ?>
            <?php if (empty($r['most_common_patterns'])): ?><tr><td colspan="2" class="empty">None in range.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <div>
            <p class="card__sub" style="margin-bottom:8px">Most Analyzed Timeframes</p>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Timeframe</th><th>Analyses</th></tr></thead><tbody>
            <?php foreach ($r['most_analyzed_timeframes'] as $tf => $count): ?><tr><td><?= e((string) $tf) ?></td><td><?= $count ?></td></tr><?php endforeach; ?>
            <?php if (empty($r['most_analyzed_timeframes'])): ?><tr><td colspan="2" class="empty">None in range.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
    </div>
    <p class="card__sub" style="margin-top:12px">"Pattern Detection Accuracy" and "Top Performing Timeframes" from the original spec both need a ground-truth outcome nothing in this schema tracks — shown instead: how often the AI's read was corroborated by the real-data pattern engine, and which timeframes get uploaded most.</p>
</div>

<?php endif; ?>
