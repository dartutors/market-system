<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Branding\BrandingSettings;
use App\Branding\BrandingUploadValidator;
use App\Branding\LogoProcessor;
use App\Core\Controller;
use App\Core\Csrf;
use Throwable;

/**
 * Module 3 "Branding System". One Primary Logo (propagates to topbar/
 * sidebar, login page, and the Reports PDF header — the only places a logo
 * can actually render in this app today) plus a dedicated Favicon pipeline
 * (browser tab, PWA manifest, Apple/Android touch icons — the one
 * placement that genuinely needs the 6-size treatment). Deliberately not 8
 * separate upload fields for 8 placements that would all render identically
 * right now — see the implementation report for why.
 */
final class BrandingController extends Controller
{
    public function index(): void
    {
        require_login();

        $this->view('branding/index', [
            'title'     => 'Branding',
            'csrf'      => Csrf::token(),
            'logoUrl'   => BrandingSettings::logoUrl(),
            'faviconUrl'=> BrandingSettings::hasFavicon() ? BrandingSettings::faviconUrl(64) : null,
        ]);
    }

    public function uploadLogo(): void
    {
        require_login();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->flashAndRedirect('Invalid CSRF token.', true);
            return;
        }

        try {
            $tmpPath = BrandingUploadValidator::validate($_FILES['logo'] ?? []);
            $destDir = base_path('public/assets/branding');
            LogoProcessor::processLogo($tmpPath, $destDir . '/logo.png');
            BrandingSettings::setLogoPath('branding/logo.png');
            $this->flashAndRedirect('Logo updated — it now appears in the sidebar, login page, and report headers.');
        } catch (Throwable $e) {
            $this->flashAndRedirect($e->getMessage(), true);
        }
    }

    public function uploadFavicon(): void
    {
        require_login();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->flashAndRedirect('Invalid CSRF token.', true);
            return;
        }

        try {
            $tmpPath = BrandingUploadValidator::validate($_FILES['favicon'] ?? []);
            $destDir = base_path('public/assets/branding');
            LogoProcessor::processFavicons($tmpPath, $destDir);
            BrandingSettings::markFaviconGenerated();
            $sizes = implode(', ', array_map(static fn(int $s): string => "{$s}×{$s}", LogoProcessor::FAVICON_SIZES));
            $this->flashAndRedirect("Favicon updated — generated {$sizes} and applied to the browser tab, PWA manifest, and touch icons.");
        } catch (Throwable $e) {
            $this->flashAndRedirect($e->getMessage(), true);
        }
    }

    private function flashAndRedirect(string $message, bool $isError = false): void
    {
        $_SESSION[$isError ? 'flash_error' : 'flash_success'] = $message;
        redirect(url('branding'));
    }
}
