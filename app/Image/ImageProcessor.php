<?php
declare(strict_types=1);
namespace App\Image;

use RuntimeException;

/**
 * Fourth pipeline stage — real GD-based optimization (GD is bundled with
 * this environment's PHP; no new dependency added). Downscales proportionally
 * if the long edge exceeds 1568px (Anthropic's documented vision sweet
 * spot — larger images are downscaled by the API anyway, so pre-resizing
 * saves upload bandwidth without losing analysis quality) and re-encodes as
 * JPEG. Writes to a new temp path — the caller's permanently stored original
 * is never touched or recompressed.
 */
final class ImageProcessor
{
    private const MAX_DIMENSION = 1568;
    private const JPEG_QUALITY  = 85;

    public function process(string $path): ProcessedImage
    {
        $dimensions = @getimagesize($path);
        if ($dimensions === false) {
            throw new RuntimeException('Cannot read image dimensions.');
        }

        [$width, $height, $type] = $dimensions;

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => throw new RuntimeException('Unsupported image type for processing.'),
        };

        if ($source === false) {
            throw new RuntimeException('Could not decode image for processing.');
        }

        $scale     = min(1.0, self::MAX_DIMENSION / max($width, $height));
        $newWidth  = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        if ($scale < 1.0) {
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
            $source = $resized;
        }

        $outputPath = $this->tempPath();
        $written    = imagejpeg($source, $outputPath, self::JPEG_QUALITY);
        imagedestroy($source);

        if (!$written) {
            throw new RuntimeException('Could not write optimized image.');
        }

        return new ProcessedImage(
            path: $outputPath,
            width: $newWidth,
            height: $newHeight,
            mimeType: 'image/jpeg',
        );
    }

    private function tempPath(): string
    {
        $dir = base_path('storage/uploads/tmp');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . uniqid('opt_', true) . '.jpg';
    }
}
