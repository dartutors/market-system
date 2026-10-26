<?php
declare(strict_types=1);
namespace App\Branding;

use RuntimeException;

/**
 * Upload validation for the Branding module (Module 3 security requirement:
 * "Validate logo uploads, image size, allowed extensions, file
 * sanitization"). Deliberately stricter than a generic upload: this app has
 * no Imagick, only GD, so only formats GD can actually rasterize are
 * accepted — SVG is refused outright rather than accepted and silently
 * mishandled (GD cannot read SVG at all).
 */
final class BrandingUploadValidator
{
    private const ALLOWED_MIME = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB

    /** @param array{name:string, type:string, tmp_name:string, error:int, size:int} $file */
    public static function validate(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($error === UPLOAD_ERR_NO_FILE ? 'No file was uploaded.' : 'Upload failed (error code ' . $error . ').');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid upload — the file was not received correctly.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Image must be between 1 byte and 5MB.');
        }

        // Trust the actual file bytes, not the client-supplied MIME type.
        $realMime = (string) mime_content_type($tmpName);
        if (!isset(self::ALLOWED_MIME[$realMime])) {
            throw new RuntimeException('Unsupported image format — only PNG, JPG, and WEBP are accepted.');
        }

        $dimensions = @getimagesize($tmpName);
        if ($dimensions === false || $dimensions[0] < 16 || $dimensions[1] < 16) {
            throw new RuntimeException('Could not read this file as a valid image (or it is smaller than 16×16).');
        }
        if ($dimensions[0] > 4000 || $dimensions[1] > 4000) {
            throw new RuntimeException('Image is too large — maximum 4000×4000 pixels.');
        }

        return $tmpName;
    }
}
