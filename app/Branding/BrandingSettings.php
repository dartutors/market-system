<?php
declare(strict_types=1);
namespace App\Branding;

use App\Models\Setting;

/**
 * Single read/write surface for branding state — every view that needs a
 * logo or favicon URL goes through here instead of poking Setting::get()
 * with hardcoded keys directly. URLs carry a `?v=` cache-busting query
 * param sourced from the setting's own updated_at, so a re-upload replaces
 * the image everywhere immediately without a manual "clear cache" step.
 */
final class BrandingSettings
{
    private const KEY_LOGO    = 'branding.logo_path';
    private const KEY_FAVICON = 'branding.favicon_generated_at';

    public static function logoUrl(): ?string
    {
        $path = Setting::get(self::KEY_LOGO);
        if ($path === null || $path === '') {
            return null;
        }
        $v = Setting::updatedAt(self::KEY_LOGO) ?? '';
        return asset($path) . '?v=' . urlencode((string) strtotime($v));
    }

    public static function faviconUrl(int $size): ?string
    {
        if (!Setting::get(self::KEY_FAVICON)) {
            return null;
        }
        $v = Setting::get(self::KEY_FAVICON, '');
        return asset("branding/favicon-{$size}.png") . '?v=' . urlencode((string) $v);
    }

    public static function hasFavicon(): bool
    {
        return Setting::get(self::KEY_FAVICON) !== null;
    }

    public static function setLogoPath(string $relativePath): void
    {
        Setting::set(self::KEY_LOGO, $relativePath);
    }

    public static function markFaviconGenerated(): void
    {
        Setting::set(self::KEY_FAVICON, (string) time());
    }

    /** Absolute filesystem path a logo image can be embedded from (e.g. for the PDF report header, which can't fetch remote URLs). */
    public static function logoFilesystemPath(): ?string
    {
        $path = Setting::get(self::KEY_LOGO);
        if ($path === null) {
            return null;
        }
        $full = base_path('public/assets/' . ltrim($path, '/'));
        return is_file($full) ? $full : null;
    }
}
