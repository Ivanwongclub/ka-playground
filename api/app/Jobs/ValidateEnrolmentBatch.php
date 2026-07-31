<?php

namespace App\Jobs;

use App\Models\EnrolmentBatch;
use App\Models\Upload;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolment\EnrolmentBatchCsvParser;
use App\Services\Enrolment\StructuralParseException;
use App\Services\Uploads\UploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * S04E STEP 1 — the ONE parse entry point (D-3). Chained off the CLEAN
 * transition: it runs only once the batch-csv upload has a scan verdict, and it
 * reads the bytes through UploadService::contents(), which THROWS unless the
 * upload is CLEAN (BI-10). So the parser physically cannot run on a non-clean
 * file — there is no other trigger, and batches.scan_gated is the
 * path-independent backstop.
 *
 * Verdict handling:
 *   PENDING (scan not finished) → retry within the window, then fail on timeout.
 *   QUARANTINED / any non-visible → batch Failed(reason=scan).
 *   CLEAN → parse (dry run); structural defect → Failed; else → Ready.
 */
class ValidateEnrolmentBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public int $backoff = 5;

    public function __construct(public readonly string $batchId) {}

    public function handle(
        ScopeContext $scope,
        UploadService $uploads,
        EnrolmentBatchCsvParser $parser,
        AuditService $audit,
    ): void {
        $scope->asSystem(
            'S04E STEP 1 batch validation (Spec Part H): reads the batch-csv upload verdict and, only on CLEAN, parses the roll into enrolment_batch_rows dispositions. The batch/upload/rows are a school-wide operation outside any single actor\'s derived scope and are system-write by construction. The parse reads bytes via UploadService::contents(), which refuses anything not CLEAN (BI-10). DRY RUN — no account or enrolment created.',
            function () use ($uploads, $parser, $audit): void {
                $batch = EnrolmentBatch::query()->find($this->batchId);
                if ($batch === null || $batch->status !== EnrolmentBatch::STATUS_SCANNING) {
                    return; // already decided — never re-open a verdict
                }
                $upload = $batch->upload_id ? Upload::query()->find($batch->upload_id) : null;
                if ($upload === null) {
                    $this->fail($batch, $audit, 'upload record missing');

                    return;
                }

                if ($upload->status === Upload::STATUS_PENDING) {
                    // scan still running — retry within the window, fail on timeout
                    if (isset($this->job) && $this->attempts() < $this->tries) {
                        $this->release($this->backoff);
                    } else {
                        $this->fail($batch, $audit, 'scan did not complete in time');
                    }

                    return;
                }
                if (! $upload->isVisible()) {
                    // QUARANTINED, or any non-clean terminal state
                    $this->fail($batch, $audit, 'scan not clean ('.$upload->status.')');

                    return;
                }

                // CLEAN → parse. contents() is the BI-10 gate (throws unless visible).
                $batch->update(['status' => EnrolmentBatch::STATUS_VALIDATING]);
                try {
                    $csv = $uploads->contents($upload);
                    $counts = $parser->parse($batch, $csv);
                } catch (StructuralParseException $e) {
                    $this->fail($batch, $audit, $e->getMessage());

                    return;
                } catch (\RuntimeException $e) {
                    // contents() refused a non-visible file — should not happen after the
                    // isVisible() check, but the gate is honoured either way.
                    $this->fail($batch, $audit, 'scan not clean');

                    return;
                }

                $batch->update([
                    'status' => EnrolmentBatch::STATUS_READY,
                    'total_rows' => $counts['total'],
                    'new_count' => $counts['new'],
                    'existing_count' => $counts['existing'],
                    'skipped_count' => $counts['skipped'],
                    'failed_count' => $counts['failed'],
                    'validated_at' => now(),
                ]);
                $audit->record(
                    entityType: 'enrolment_batch',
                    entityId: $batch->id,
                    action: 'batch.validated',
                    fromState: EnrolmentBatch::STATUS_VALIDATING,
                    toState: EnrolmentBatch::STATUS_READY,
                    payloadAfter: $counts,
                );
            },
        );
    }

    private function fail(EnrolmentBatch $batch, AuditService $audit, string $reason): void
    {
        $from = $batch->status;
        $batch->update(['status' => EnrolmentBatch::STATUS_FAILED, 'failure_reason' => $reason]);
        $audit->record(
            entityType: 'enrolment_batch',
            entityId: $batch->id,
            action: 'batch.failed',
            fromState: $from,
            toState: EnrolmentBatch::STATUS_FAILED,
            reason: $reason,
        );
    }
}
