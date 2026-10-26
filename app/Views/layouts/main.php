<?php
$viewFile = app_path('Views/' . $view . '.php');
$faviconUrl32  = App\Branding\BrandingSettings::hasFavicon() ? App\Branding\BrandingSettings::faviconUrl(32)  : null;
$faviconUrl180 = App\Branding\BrandingSettings::hasFavicon() ? App\Branding\BrandingSettings::faviconUrl(180) : null;
$faviconUrl512 = App\Branding\BrandingSettings::hasFavicon() ? App\Branding\BrandingSettings::faviconUrl(512) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Market Signal System') ?> — MSS</title>
    <?php if ($faviconUrl32): ?>
        <link rel="icon" type="image/png" sizes="32x32" href="<?= e($faviconUrl32) ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="<?= e($faviconUrl180) ?>">
    <?php else: ?>
        <link rel="icon" type="image/svg+xml" href="<?= e(asset('favicon.svg')) ?>">
    <?php endif; ?>
    <link rel="manifest" href="<?= e(url('manifest.json')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script>
    (function () {
        try {
            var t = localStorage.getItem('mss-theme');
            if (t === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        } catch (e) {}
    }());
    </script>
</head>
<body>
<div class="page-wrap">
    <?php require app_path('Views/partials/topbar.php'); ?>
    <div class="shell">
        <?php if (is_logged_in()): ?>
        <?php require app_path('Views/partials/sidebar.php'); ?>
        <div class="sb-scrim" id="sb-scrim"></div>
        <?php endif; ?>
        <main class="container">
            <?php require app_path('Views/partials/flash.php'); ?>
            <?php require $viewFile; ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/shell.js')) ?>"></script>
</body>
</html>
