<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="flash error"><?= e((string) $_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="flash success"><?= e((string) $_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
