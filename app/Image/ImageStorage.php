<?php
declare(strict_types=1);
namespace App\Image;

use RuntimeException;

/**
 * Third pipeline stage — moves a validated file from staging into permanent
 * storage under storage/uploads/charts/{Y}/{m}/. Pure filesystem; the DB
 * write (uploaded_charts row) belongs to the future feature's controller,
 * not this scaffolding.
 */
final class ImageStorage
{
    public function persist(string $stagedPath): string
    {
        $destinationDir = base_path('storage/uploads/charts/' . date('Y') . '/' . date('m'));
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        $destination = $destinationDir . DIRECTORY_SEPARATOR . basename($stagedPath);

        if (!rename($stagedPath, $destination)) {
            throw new RuntimeException('Could not move staged image into permanent storage.');
        }

        return $destination;
    }
}
