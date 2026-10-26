<div class="page-header">
    <h2 class="page-title">Branding</h2>
    <p class="page-sub">Upload a logo and favicon once — both propagate automatically to every place this app can actually show one: sidebar/topbar, login page, report PDF headers, browser tab, and PWA/touch icons.</p>
</div>

<div class="db-row db-row--bottom">

    <div class="card">
        <div class="card__hd">
            <h3 class="card__title">Primary Logo</h3>
            <span class="card__sub">PNG, JPG, or WEBP · up to 5MB</span>
        </div>
        <?php if ($logoUrl): ?>
            <div class="brand-preview"><img src="<?= e($logoUrl) ?>" alt="Current logo"></div>
        <?php else: ?>
            <p class="empty">No logo uploaded yet — the topbar shows its default text mark.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('branding/upload-logo')) ?>" enctype="multipart/form-data" style="margin-top:14px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required class="form-input" style="margin-bottom:10px">
            <button type="submit" class="btn btn--scan">Upload Logo</button>
        </form>
        <p class="card__sub" style="margin-top:10px">Resized proportionally to a 480px-wide PNG (transparency preserved), applied to the sidebar/topbar brand, the login page, and Reports PDF headers.</p>
    </div>

    <div class="card">
        <div class="card__hd">
            <h3 class="card__title">Favicon</h3>
            <span class="card__sub">PNG, JPG, or WEBP · up to 5MB</span>
        </div>
        <?php if ($faviconUrl): ?>
            <div class="brand-preview brand-preview--sm"><img src="<?= e($faviconUrl) ?>" alt="Current favicon"></div>
        <?php else: ?>
            <p class="empty">No favicon uploaded yet — the browser tab shows the default icon.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('branding/upload-favicon')) ?>" enctype="multipart/form-data" style="margin-top:14px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp" required class="form-input" style="margin-bottom:10px">
            <button type="submit" class="btn btn--scan">Upload Favicon</button>
        </form>
        <p class="card__sub" style="margin-top:10px">Generates 16×16, 32×32, 64×64, 128×128, 180×180, and 512×512 PNGs — wired into the browser tab icon, apple-touch-icon, and the PWA manifest (Android home-screen icon).</p>
    </div>

</div>

<div class="card" style="margin-top:20px">
    <div class="card__hd"><h3 class="card__title">Upload Security</h3></div>
    <ul class="bt-ai-reasons">
        <li>File type is verified from the actual file bytes (<code>mime_content_type</code>), never trusted from the browser-supplied filename or MIME header.</li>
        <li>Only PNG, JPG, and WEBP are accepted — this app has no Imagick, so SVG is refused rather than accepted and mishandled (GD cannot rasterize it).</li>
        <li>Every upload is re-encoded from scratch through GD, never saved byte-for-byte — this strips any payload hidden in image metadata.</li>
        <li>5MB size cap and a 4000×4000px dimension cap, both enforced before any processing runs.</li>
    </ul>
</div>
