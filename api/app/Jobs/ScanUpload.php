<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\Audit\AuditService;
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

    public function handle(VirusScanner $scanner, AuditService $audit): void
    {
        $upload = Upload::query()->find($this->uploadId);
        if ($upload === null || $upload->status !== Upload::STATUS_PENDING) {
            return; // already decided — never re-open a verdict
        }

        $disk = Storage::disk($upload->disk);
        $signature = $scanner->scan((string) $disk->get($upload->path));

        if ($signature === null) {
            $cleanPath = str_replace(
                config('uploads.paths.pending'),
                config('uploads.paths.clean'),
                $upload->path,
            );
            $disk->move($upload->path, $cleanPath);
            $upload->update([
                'path' => $cleanPath,
                'status' => Upload::STATUS_CLEAN,
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
            reason: "ClamAV hit: {$signature}",
            payloadAfter: ['signature' => $signature, 'context' => $upload->context],
        );
        // Admin alert: critical log now; the K-engine notification channel lands in S09
        Log::critical('Upload quarantined by ClamAV', [
            'upload_id' => $upload->id,
            'signature' => $signature,
            'context' => $upload->context,
        ]);
    }
}
