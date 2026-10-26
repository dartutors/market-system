<div class="page-header">
    <h2 class="page-title">Institutional Analysis</h2>
    <p class="page-sub">Smart-Money-Concepts-style market structure: Order Blocks, Fair Value Gaps, Liquidity Pools, Equal Highs/Lows, Break of Structure, Change of Character, Premium/Discount Zones, Liquidity Sweeps, Mitigation Blocks. Educational only — no real trading.</p>
</div>

<!-- ── Run form ─────────────────────────────────────────────────────── -->
<div class="card card--accent">
    <form method="post" action="<?= e(url('smart-money/analyze')) ?>" class="bt-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="bt-form__fields">
            <div class="form-group">
                <label for="sm-symbol">Market pair</label>
                <select id="sm-symbol" name="symbol" class="form-select">
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['symbol']) ?>"
                        <?= isset($selectedSymbol) && $selectedSymbol === $m['symbol'] ? 'selected' : '' ?>>
                        <?= e($m['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="sm-timeframe">Timeframe</label>
                <select id="sm-timeframe" name="timeframe" class="form-select">
                    <?php foreach ($timeframes as $tf): ?>
                    <option value="<?= e($tf) ?>"
                        <?= isset($selectedTimeframe) ? ($selectedTimeframe === $tf ? 'selected' : '') : ($tf === 'H1' ? 'selected' : '') ?>>
                        <?= e($tf) ?>
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
                Analyze Structure
            </button>
        </div>
    </form>
</div>

<?php if (isset($result)): ?>

    <?php if (isset($result['error'])): ?>
    <div class="flash error"><?= e($result['error']) ?></div>

    <?php else:
        $grouped     = $result['grouped'];
        $totalEvents = array_sum(array_map(static fn($g) => count($g['events']), $grouped));
    ?>

    <div class="bt-stats-grid">
        <div class="stat-block">
            <span class="stat-block__val"><?= e($result['label']) ?></span>
            <span class="stat-block__lbl">Market</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= e($result['timeframe']) ?></span>
            <span class="stat-block__lbl">Timeframe</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= (int) $result['bars_used'] ?></span>
            <span class="stat-block__lbl">Bars Analyzed</span>
        </div>
        <div class="stat-block <?= $totalEvents > 0 ? 'stat-block--green' : '' ?>">
            <span class="stat-block__val"><?= (int) $totalEvents ?></span>
            <span class="stat-block__lbl">Structures Found</span>
        </div>
    </div>

    <?php foreach ($grouped as $key => $group): ?>
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title"><?= e($group['label']) ?></h3>
            <span class="badge"><?= count($group['events']) ?></span>
        </div>

        <?php if (empty($group['events'])): ?>
        <p class="stat-block__lbl">None detected in the current data.</p>
        <?php else: ?>
        <ul class="bt-ai-reasons">
            <?php foreach ($group['events'] as $ev): ?>
            <li>
                <span class="badge badge--bias badge--<?= e($ev->direction) ?>">
                    <?= e(ucfirst($ev->direction)) ?>
                </span>
                <strong><?= e($ev->label) ?></strong>
                <?php if ($ev->zoneHigh !== null && $ev->zoneLow !== null): ?>
                    — zone <?= e(number_format($ev->zoneLow, 5)) ?> - <?= e(number_format($ev->zoneHigh, 5)) ?>
                <?php endif; ?>
                <?php if ($ev->mitigated): ?>
                    <span class="stat-block__lbl">(mitigated)</span>
                <?php endif; ?>
                <br><span class="stat-block__lbl"><?= e($ev->description) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

<?php endif; ?>
