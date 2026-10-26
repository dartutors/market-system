<?php
declare(strict_types=1);
namespace App\Image\Contracts;

/**
 * Common shape for each image-pipeline stage: Upload → Validation → Storage
 * → Processing. Each stage takes a working file path and returns a path
 * (the same one if unchanged) or throws on failure.
 */
interface ImagePipelineStageInterface
{
    public function handle(string $path): string;
}
