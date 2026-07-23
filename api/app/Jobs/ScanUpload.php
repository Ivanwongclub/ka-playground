<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\Audit\AuditService;
use App\Services\Uploads\ImageHardener;
use App\Services\Uploads\VirusScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Queued ClamAV scan (2.12). Clean → file moves to the clean area and becomes
 * visible. Hit → quarantine + alert + audit event (BI-10). A scanner failure
 * retries and, exhausted, leaves the file pending — never visible.
 */
class ScanUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $uploadId) {}

    public function handle(VirusScanner $scanner, AuditService $audit, ImageHardener $hardener): void
    {
        $upload = Upload::query()->find($this->uploadId);
        if ($upload === null || $upload->status !== Upload::STATUS_PENDING) {
            return; // already decided — never re-open a verdict
        }

        $disk = Storage::disk($upload->disk);
        $original = (string) $disk->get($upload->path);
        // The scanner sees the ORIGINAL bytes — hardening runs only after a
        // clean verdict, or a metadata-borne signature would be silently erased
        $signature = $scanner->scan($original);

        if ($signature === null) {
            $contents = $original;
            if (str_starts_with($upload->mime_type, 'image/')) {
                try {
                    $contents = $hardener->harden($original, $upload->mime_type);
                } catch (\RuntimeException $e) {
                    // Scanned clean but cannot be neutralised — refuse visibility
                    $this->quarantine($upload, $audit, "image hardening failed: {$e->getMessage()}");

                    return;
                }
            }

            $cleanPath = str_replace(
                config('uploads.paths.pending'),
                config('uploads.paths.clean'),
                $upload->path,
            );
            $disk->put($cleanPath, $contents);
            $disk->delete($upload->path);
            $upload->update([
                'path' => $cleanPath,
                'status' => Upload::STATUS_CLEAN,
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'scanned_at' => now(),
            ]);
            $audit->record(
                entityType: 'upload',
                entityId: $upload->id,
                action: 'upload.scan_passed',
                fromState: Upload::STATUS_PENDING,
                toState: Upload::STATUS_CLEAN,
            );

            return;
        }

        $this->quarantine($upload, $audit, "ClamAV hit: {$signature}", $signature);
    }

    private function quarantine(Upload $upload, AuditService $audit, string $reason, ?string $signature = null): void
    {
        $disk = Storage::disk($upload->disk);
        $quarantinePath = str_replace(
            config('uploads.paths.pending'),
            config('uploads.paths.quarantine'),
            $upload->path,
        );
        $disk->move($upload->path, $quarantinePath);
        $upload->update([
            'path' => $quarantinePath,
            'status' => Upload::STATUS_QUARANTINED,
            'scan_signature' => $signature,
            'scanned_at' => now(),
        ]);
        $audit->record(
            entityType: 'upload',
            entityId: $upload->id,
            action: 'upload.quarantined',
            fromState: Upload::STATUS_PENDING,
            toState: Upload::STATUS_QUARANTINED,
            reason: $reason,
            payloadAfter: ['signature' => $signature, 'context' => $upload->context],
        );
        // Admin alert: critical log now; the K-engine notification channel lands in S09
        Log::critical('Upload quarantined', [
            'upload_id' => $upload->id,
            'reason' => $reason,
            'signature' => $signature,
            'context' => $upload->context,
        ]);
    }
}
