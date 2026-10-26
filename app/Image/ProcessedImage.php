<?php
declare(strict_types=1);
namespace App\Image;

final class ProcessedImage
{
    public function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly string $mimeType,
    ) {
    }
}
