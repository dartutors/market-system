<?php
/**
 * Primary navigation (Section 14). Every item here already had a live route
 * before this redesign — nothing was removed, only regrouped under a
 * command-center-style sidebar. Items with no dedicated page yet (Scanner,
 * Signals, News) point at their existing dashboard anchor instead of a new
 * page. Reports shipped in Phase 2.4. Settings still has no backing
 * feature — shown disabled rather than linked to something that doesn't exist.
 */
$uri = $_SERVER['REQUEST_URI'] ?? '';
$is  = static fn(string $needle): bool => str_contains($uri, $needle);

$icon = static function (string $name): string {
    $paths = [
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'eye'       => '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'activity'  => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'bar-chart' => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="13" y="8" width="3" height="10"/><rect x="19" y="5" width="3" height="13"/>',
        'layers'    => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'shield'    => '<path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/>',
        'newspaper' => '<path d="M4 4h13a2 2 0 0 1 2 2v13a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V4Z"/><path d="M16 4h2a2 2 0 0 1 2 2v11"/><path d="M8 9h6M8 13h6M8 17h4"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'message'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10Z"/>',
        'book'      => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
        'brain'     => '<path d="M9.5 2a3.5 3.5 0 0 0-3.5 3.5V6a3 3 0 0 0-2 5 3 3 0 0 0 2 5v.5A3.5 3.5 0 0 0 9.5 20"/><path d="M14.5 2A3.5 3.5 0 0 1 18 5.5V6a3 3 0 0 1 2 5 3 3 0 0 1-2 5v.5a3.5 3.5 0 0 1-3.5 3.5"/><path d="M9.5 2v18M14.5 2v18"/>',
        'flask'     => '<path d="M9 2v6L3.5 18a2 2 0 0 0 1.8 3h13.4a2 2 0 0 0 1.8-3L15 8V2"/><path d="M9 2h6M8 14h8"/>',
        'trend'     => '<path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h4v4"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'star'      => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
    ];
    return '<svg class="sb-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
};

/** @param array<string,mixed> $opts */
$link = static function (string $href, string $label, string $iconName, array $opts = []) use ($icon): void {
    $active   = !empty($opts['active']);
    $disabled = !empty($opts['disabled']);
    $star     = !empty($opts['star']);
    if ($disabled) {
        echo '<span class="sb-link sb-link--disabled" title="Not built yet">' . $icon($iconName) . '<span class="sb-link__label">' . e($label) . '</span><span class="sb-soon">Soon</span></span>';
        return;
    }
    echo '<a href="' . e($href) . '" class="sb-link' . ($active ? ' is-active' : '') . '">'
        . $icon($iconName) . '<span class="sb-link__label">' . e($label) . '</span>'
        . ($star ? '<span class="sb-star" title="Primary workflow">&#9733;</span>' : '')
        . '</a>';
};
?>
<aside class="sidebar" id="sidebar">
    <nav class="sb-nav">
        <p class="sb-caption">Primary</p>
        <?php $link(url('dashboard'), 'Dashboard', 'grid', ['active' => $is('/dashboard') && !$is('#')]); ?>
        <?php $link(url('vision'), 'AI Vision', 'eye', ['active' => $is('/vision'), 'star' => true, 'disabled' => !feature_enabled('AI_VISION')]); ?>

        <p class="sb-caption">Workspace</p>
        <?php $link(url('dashboard') . '#watchlist', 'Watchlist', 'star'); ?>
        <?php $link(url('dashboard') . '#scanner', 'Scanner', 'search'); ?>
        <?php $link(url('dashboard') . '#signals', 'Signals', 'activity'); ?>
        <?php $link(url('market-intelligence'), 'Market Analysis', 'bar-chart', ['active' => $is('/market-intelligence')]); ?>
        <?php $link(url('multi-timeframe'), 'Multi-Timeframe', 'layers', ['active' => $is('/multi-timeframe'), 'disabled' => !feature_enabled('MULTI_TIMEFRAME')]); ?>
        <?php $link(url('smart-money'), 'Institutional Analysis', 'shield', ['active' => $is('/smart-money'), 'disabled' => !feature_enabled('SMART_MONEY')]); ?>
        <?php $link(url('dashboard') . '#news', 'News', 'newspaper'); ?>
        <?php $link(url('calendar'), 'Economic Calendar', 'calendar', ['active' => $is('/calendar')]); ?>

        <p class="sb-caption">Decision Support</p>
        <?php $link(url('trade-coach'), 'Trade Coach', 'message', ['active' => $is('/trade-coach'), 'disabled' => !feature_enabled('TRADE_COACH')]); ?>
        <?php $link(url('risk-management'), 'Risk Management', 'shield', ['active' => $is('/risk-management'), 'disabled' => !feature_enabled('RISK_MANAGEMENT')]); ?>
        <?php $link(url('trade-journal'), 'Trade Journal', 'book', ['active' => $is('/trade-journal'), 'disabled' => !feature_enabled('TRADE_JOURNAL')]); ?>
        <?php $link(url('learning'), 'AI Learning', 'brain', ['active' => $is('/learning'), 'disabled' => !feature_enabled('AI_LEARNING')]); ?>

        <p class="sb-caption">Testing</p>
        <?php $link(url('backtest'), 'Backtest', 'flask', ['active' => $is('/backtest') && !$is('/backtest/advanced')]); ?>
        <?php $link(url('backtest/advanced'), 'Advanced Backtesting', 'trend', ['active' => $is('/backtest/advanced'), 'disabled' => !feature_enabled('ADVANCED_BACKTESTING')]); ?>

        <p class="sb-caption">System</p>
        <?php $link(url('reports'), 'Reports', 'file', ['active' => $is('/reports')]); ?>
        <?php $link(url('branding'), 'Branding', 'image', ['active' => $is('/branding')]); ?>
        <?php $link('', 'Settings', 'settings', ['disabled' => true]); ?>
    </nav>
</aside>
