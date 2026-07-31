<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateEnrolmentBatch;
use App\Models\EnrolmentBatch;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Uploads\UploadService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S04E STEP 1 — bulk-enrolment intake (Spec Part H, H1). A school admin uploads
 * a roll CSV for their OWN school; it is virus-scanned before a single row is
 * parsed (BI-10), then dry-run-validated into per-row dispositions. Nothing is
 * created here — commit is STEP 2.
 */
class EnrolmentBatchController extends Controller
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function upload(Request $request, VirusScanner $scanner): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'school_id' => ['required', 'integer'],
        ]);
        $admin = $request->user();

        // Roll authority at the edge — the batch is for a school this admin
        // administers (per-row authority is still create()'s job at commit).
        $administers = DB::table('school_admin_links')
            ->where('school_admin_id', $admin->id)
            ->where('school_id', $validated['school_id'])
            ->where('status', 'active')->exists();
        if (! $administers) {
            return response()->json(['message' => 'Not a school you administer'], 403);
        }

        // Fail-closed (D-4): if the scanner is unreachable, refuse BEFORE intake —
        // nothing is persisted, so there is no half-created batch stuck pending.
        if (! $scanner->isAvailable()) {
            return response()->json(['message' => 'Virus scanner unavailable — batch upload refused'], 503);
        }

        $upload = $this->uploads->intake($request->file('file'), 'batch-csv', $admin);

        $batch = $this->scope->asSystem(
            'S04E STEP 1 batch creation (Spec Part H): records the enrolment_batches row for a school-wide operation (system-write by construction) and chains the parse off the scan verdict. Roll authority was checked at the edge; per-row authority is create()\'s at commit.',
            function () use ($admin, $validated, $upload): EnrolmentBatch {
                $batch = EnrolmentBatch::query()->create([
                    'id' => (string) \Illuminate\Support\Str::uuid7(),
                    'school_id' => $validated['school_id'],
                    'upload_id' => $upload->id,
                    'status' => EnrolmentBatch::STATUS_SCANNING,
                    'created_by' => $admin->id,
                ]);
                $this->audit->record(
                    entityType: 'enrolment_batch',
                    entityId: $batch->id,
                    action: 'batch.created',
                    toState: EnrolmentBatch::STATUS_SCANNING,
                    payloadAfter: ['school_id' => $validated['school_id'], 'upload_id' => $upload->id],
                    actor: $admin,
                );
                ValidateEnrolmentBatch::dispatch($batch->id);

                return $batch;
            },
        );

        return response()->json(['batch_id' => $batch->id, 'status' => $batch->status], 202);
    }

    /**
     * The dry-run report — batch status, counts, and every row's disposition/
     * reason. RLS scopes it: an admin of another school gets 404 (five-branch).
     */
    public function show(Request $request, string $batch): JsonResponse
    {
        $model = EnrolmentBatch::query()->find($batch);
        if ($model === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'batch_id' => $model->id,
            'status' => $model->status,
            'failure_reason' => $model->failure_reason,
            'counts' => [
                'total' => $model->total_rows,
                'new' => $model->new_count,
                'existing' => $model->existing_count,
                'skipped' => $model->skipped_count,
                'failed' => $model->failed_count,
            ],
            'rows' => $model->rows()->orderBy('row_number')->get([
                'row_number', 'name', 'email', 'status', 'disposition', 'reason', 'matched_user_id',
            ]),
        ]);
    }
}
