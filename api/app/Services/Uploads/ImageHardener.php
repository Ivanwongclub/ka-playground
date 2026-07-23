<?php

namespace App\Services\Uploads;

use RuntimeException;

/**
 * Pixel-level re-encode via GD (O2): EXIF, comment segments and appended
 * payloads do not survive. Runs AFTER a clean scan verdict — the scanner must
 * see the original bytes, or a signature hidden in metadata is neutralised
 * without ever being detected and alerted.
 */
class ImageHardener
{
    public function harden(string $contents, string $mime): string
    {
        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new RuntimeException('image could not be decoded for re-encode');
        }

        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 90),
            default => throw new RuntimeException("unexpected image mime {$mime}"),
        };
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
