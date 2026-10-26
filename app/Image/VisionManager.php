<?php
declare(strict_types=1);
namespace App\Image;

use App\AI\AIManager;
use App\AI\AIResponse;
use RuntimeException;

/**
 * Real implementation — reads an (already optimized) image, base64-encodes
 * it, and calls AIManager::completeForTask('vision', ...) so cost/routing
 * rules configured for the 'vision' task apply automatically. Mirrors
 * AIManager's own call shape rather than inventing a new one.
 */
final class VisionManager
{
    public function __construct(private AIManager $aiManager = new AIManager())
    {
    }

    public function analyze(string $imagePath, string $systemPrompt, string $userPrompt): ?AIResponse
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException("Image not found for vision analysis: {$imagePath}");
        }

        $bytes = file_get_contents($imagePath);
        if ($bytes === false) {
            throw new RuntimeException("Could not read image for vision analysis: {$imagePath}");
        }

        $maxTokens = max(300, (int) env('VISION_MAX_TOKENS', 1024));

        return $this->aiManager->completeForTask('vision', $systemPrompt, $userPrompt, [
            'image_base64'     => base64_encode($bytes),
            'image_media_type' => $this->mediaTypeFor($imagePath),
            'max_tokens'       => $maxTokens,
            'service'          => 'vision_analysis',
        ]);
    }

    private function mediaTypeFor(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
