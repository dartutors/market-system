<?php
declare(strict_types=1);
namespace App\Image;

use App\Image\Contracts\ImagePipelineStageInterface;
use finfo;
use RuntimeException;

/**
 * Second pipeline stage. Real, working validation — no new dependencies:
 * finfo for mime type, getimagesize() for dimensions (guards against a
 * spoofed file extension), plus a size cap.
 */
final class ImageValidator implements ImagePipelineStageInterface
{
    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function handle(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('File not found for validation.');
        }

        $maxBytes = max(1, (int) env('CHART_IMAGE_MAX_MB', 8)) * 1024 * 1024;
        $size     = filesize($path);
        if ($size === false || $size > $maxBytes) {
            throw new RuntimeException('Image exceeds the maximum allowed size.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path) ?: '';
        if (!\in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException("Unsupported image type: {$mime}.");
        }

        if (@getimagesize($path) === false) {
            throw new RuntimeException('File is not a readable image (dimension check failed).');
        }

        return $path;
    }
}
