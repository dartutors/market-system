<div class="card small-card">
    <?php $fpLogo = App\Branding\BrandingSettings::logoUrl(); ?>
    <?php if ($fpLogo): ?>
        <img src="<?= e($fpLogo) ?>" alt="Market Signal System" class="login-logo">
    <?php endif; ?>
    <h2>Forgot Password</h2>
    <p class="auth-hint">Enter your account email and we'll send a link to reset your password.</p>

    <form method="post" action="<?= e(url('forgot-password')) ?>">
        <input type="hidden" name="_csrf" value="<?= e((string) $csrf) ?>">

        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autocomplete="email" autofocus>

        <button type="submit" class="btn btn-primary">Send Reset Link</button>
    </form>

    <p class="auth-back"><a href="<?= e(url('login')) ?>">← Back to login</a></p>
</div>
