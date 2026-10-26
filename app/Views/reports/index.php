<div class="page-header">
    <h2 class="page-title">Reports</h2>
    <p class="page-sub">Every figure below reads from this app's own tables — nothing here is a mockup. Where a requested metric has no real data source (e.g. calendar outcome tracking), the report says so instead of inventing one.</p>
</div>

<div class="rpt-grid">
    <?php foreach ($reports as $slug => $meta): ?>
    <a href="<?= e(url('reports/' . $slug)) ?>" class="rpt-card">
        <h3 class="rpt-card__title"><?= e($meta['label']) ?></h3>
        <p class="rpt-card__desc"><?= e($meta['description']) ?></p>
        <span class="rpt-card__cta">Open report →</span>
    </a>
    <?php endforeach; ?>
</div>
