<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Validates and stores uploaded images with MIME sniffing, an extension allow-list and random names
 * (spec storage.rules). Brand files (logo, signature) are stored byte-for-byte: never resized or re-compressed.
 */
class UploadService
{
    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const MAX_BYTES = 8 * 1024 * 1024;

    /** @return array{path: string, url: string, mime: string, size: int} */
    public function storeBrandImage(UploadedFile $file, string $kind): array
    {
        $mime = $this->sniffMime($file);
        if ($mime === null || $file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw new ApiException('UPLOAD_INVALID', 422, '', ['file' => ['errors.UPLOAD_INVALID']]);
        }
        $ext = self::ALLOWED[$mime];
        $name = $kind.'-'.Str::lower(Str::random(24)).'.'.$ext;
        $dir = public_path('uploads/brand');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file->move($dir, $name);
        @chmod($dir.DIRECTORY_SEPARATOR.$name, 0644);

        return [
            'path' => 'uploads/brand/'.$name,
            'url' => '/uploads/brand/'.$name,
            'mime' => $mime,
            'size' => (int) filesize($dir.DIRECTORY_SEPARATOR.$name),
        ];
    }

    /** Sniff the real MIME type from file content, not the client-supplied name. */
    public function sniffMime(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime === false || ! array_key_exists($mime, self::ALLOWED)) {
            return null;
        }
        // Second guard: must decode as an image.
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        return $mime;
    }

    public function deletePublic(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        $full = public_path(ltrim($relativePath, '/'));
        if (str_starts_with(realpath(dirname($full)) ?: '', public_path('uploads')) && is_file($full)) {
            @unlink($full);
        }
    }
}
