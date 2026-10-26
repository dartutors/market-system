<?php
declare(strict_types=1);
namespace App\Image;

use RuntimeException;

/**
 * First stage of the future AI Vision pipeline (Upload -> Validation ->
 * Storage -> Processing -> AI Vision -> Decision Engine -> Annotated
 * Output -> History). Moves an uploaded file to a staging directory under a
 * UUID filename. No screenshot analysis happens here or anywhere yet.
 */
final class UploadService
{
    /**
     * @param array{tmp_name?:string,name?:string,error?:int} $file One entry from $_FILES
     */
    public function stage(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code ' . ($file['error'] ?? -1) . '.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('No valid uploaded file provided.');
        }

        // Always .upload, never the client-supplied extension — ImageValidator
        // (next pipeline stage) determines the real type from file content
        // (finfo/getimagesize), not the name, so nothing downstream needs a
        // "real" extension here. Previously this preserved whatever
        // extension the client claimed (stripped to [a-z0-9], so ".php"
        // survived intact) — low practical risk given storage/.htaccess
        // denies all web access to this directory, but there's no reason to
        // ever have an executable-looking name on disk when nothing needs it.
        $destination = $this->stagingDir() . DIRECTORY_SEPARATOR . $this->uuid() . '.upload';

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Could not move uploaded file to the staging directory.');
        }

        return $destination;
    }

    private function stagingDir(): string
    {
        $dir = base_path('storage/uploads/tmp');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
