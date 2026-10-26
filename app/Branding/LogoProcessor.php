<?php
declare(strict_types=1);
namespace App\Branding;

use RuntimeException;

/**
 * Raw-GD image processing for uploaded branding assets — a separate class
 * from App\Image\ImageProcessor (the AI Vision chart-upload processor)
 * rather than a reuse: that one forces JPEG output and a single fixed
 * dimension, which would destroy transparency on a logo/favicon. Every
 * output here is re-encoded from scratch via GD (never a byte-for-byte
 * copy of the upload), which — as a side effect — strips any embedded
 * payload hidden in image metadata, the "file sanitization" security
 * requirement for this module.
 */
final class LogoProcessor
{
    public const FAVICON_SIZES = [16, 32, 64, 128, 180, 512];
    private const LOGO_MAX_WIDTH = 480;

    /** Resizes proportionally to a max width, preserves transparency, saves as PNG. */
    public static function processLogo(string $srcPath, string $destPath): void
    {
        $image = self::load($srcPath);
        [$srcW, $srcH] = [imagesx($image), imagesy($image)];

        if ($srcW > self::LOGO_MAX_WIDTH) {
            $destW = self::LOGO_MAX_WIDTH;
            $destH = (int) round($srcH * ($destW / $srcW));
        } else {
            $destW = $srcW;
            $destH = $srcH;
        }

        $out = self::resample($image, $srcW, $srcH, $destW, $destH);
        self::savePng($out, $destPath);
        imagedestroy($image);
        imagedestroy($out);
    }

    /**
     * Generates every size in FAVICON_SIZES as its own square PNG — scaled
     * to fit (never cropped, so nothing of the logo is cut off) and
     * centered on a transparent canvas.
     *
     * @return array<int, string> size => absolute file path written
     */
    public static function processFavicons(string $srcPath, string $destDir): array
    {
        $image = self::load($srcPath);
        [$srcW, $srcH] = [imagesx($image), imagesy($image)];
        $written = [];

        foreach (self::FAVICON_SIZES as $size) {
            $canvas = imagecreatetruecolor($size, $size);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);

            $scale = min($size / $srcW, $size / $srcH);
            $fitW  = max(1, (int) round($srcW * $scale));
            $fitH  = max(1, (int) round($srcH * $scale));
            $offX  = (int) round(($size - $fitW) / 2);
            $offY  = (int) round(($size - $fitH) / 2);

            imagecopyresampled($canvas, $image, $offX, $offY, 0, 0, $fitW, $fitH, $srcW, $srcH);

            $path = rtrim($destDir, '/\\') . "/favicon-{$size}.png";
            self::savePng($canvas, $path);
            imagedestroy($canvas);
            $written[$size] = $path;
        }

        imagedestroy($image);
        return $written;
    }

    /** @return \GdImage */
    private static function load(string $path)
    {
        $mime = (string) mime_content_type($path);
        $image = match ($mime) {
            'image/png'  => @imagecreatefrompng($path),
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
        if ($image === false) {
            throw new RuntimeException('Could not decode the uploaded image.');
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        return $image;
    }

    /** @param \GdImage $image @return \GdImage */
    private static function resample($image, int $srcW, int $srcH, int $destW, int $destH)
    {
        $out = imagecreatetruecolor($destW, $destH);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefill($out, 0, 0, $transparent);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $destW, $destH, $srcW, $srcH);
        return $out;
    }

    /** @param \GdImage $image */
    private static function savePng($image, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!imagepng($image, $path, 6)) {
            // Full server path stays in the log, not the exception message —
            // BrandingController flashes $e->getMessage() straight back to
            // the browser, and a raw filesystem path there is server
            // structure disclosure for no user-facing benefit.
            error_log("[LogoProcessor] Failed to write PNG to {$path}");
            throw new RuntimeException('Failed to save the processed image — check storage permissions.');
        }
    }
}
