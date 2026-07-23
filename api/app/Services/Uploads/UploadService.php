<?php

namespace App\Services\Uploads;

use App\Jobs\ScanUpload;
use App\Models\Upload;
use App\Services\Audit\AuditService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The single file-intake path (2.12 / BI-10). Validates per-context MIME and
 * size, re-encodes images server-side (dropping EXIF and any embedded
 * payloads), stores to a private pending area and queues the ClamAV scan.
 * A file becomes visible only when the scan passes.
 */
class UploadService
{
    public function __construct(private readonly AuditService $audit) {}

    public function intake(UploadedFile $file, string $context, ?Authenticatable $actor = null): Upload
    {
        $config = config("uploads.contexts.{$context}");
        if ($config === null) {
            throw new RuntimeException("Unknown upload context '{$context}' — every surface must name one (2.12)");
        }

        // Server-side detection (finfo on content), never the client-supplied type
        $mime = $file->getMimeType();
        if (! in_array($mime, $config['mimes'], true)) {
            throw ValidationException::withMessages([
                'file' => ["File type {$mime} is not accepted here"],
            ]);
        }
        if ($file->getSize() > $config['max_bytes']) {
            throw ValidationException::withMessages([
                'file' => ['File exceeds the size limit for this upload'],
            ]);
        }

        $contents = (string) file_get_contents($file->getRealPath());
        if (str_starts_with($mime, 'image/')) {
            $contents = $this->reencodeImage($contents, $mime);
        }

        $id = (string) Str::uuid7();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $disk = config('uploads.disk');
        $path = config('uploads.paths.pending')."/{$id}.{$extension}";
        Storage::disk($disk)->put($path, $contents);

        $upload = Upload::query()->create([
            'id' => $id,
            'context' => $context,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'status' => Upload::STATUS_PENDING,
            'uploaded_by' => $actor?->getAuthIdentifier(),
        ]);

        $this->audit->record(
            entityType: 'upload',
            entityId: $upload->id,
            action: 'upload.received',
            toState: Upload::STATUS_PENDING,
            payloadAfter: ['context' => $context, 'mime' => $mime, 'sha256' => $upload->sha256],
            actor: $actor,
        );

        ScanUpload::dispatch($upload->id);

        return $upload;
    }

    /**
     * Contents of a stored upload — refuses anything not scanned clean (BI-10).
     */
    public function contents(Upload $upload): string
    {
        if (! $upload->isVisible()) {
            throw new RuntimeException("Upload {$upload->id} is not visible (status: {$upload->status}) — BI-10");
        }

        return (string) Storage::disk($upload->disk)->get($upload->path);
    }

    /**
     * Decode and re-export the image via GD: EXIF, comment blocks and any
     * appended payloads do not survive a pixel-level re-encode (O2).
     */
    private function reencodeImage(string $contents, string $mime): string
    {
        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw ValidationException::withMessages(['file' => ['Image could not be decoded']]);
        }

        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 90),
            default => throw new RuntimeException("Unexpected image mime {$mime}"),
        };
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
