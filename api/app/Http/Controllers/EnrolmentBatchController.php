<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateEnrolmentBatch;
use App\Models\EnrolmentBatch;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolment\EnrolmentBatchCommitService;
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
            'programme_id' => ['required', 'integer'],   // D-10 — the cohort's enrolment target
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

        // The enrolment target must be a published programme (D-10).
        $programme = DB::table('programmes')->where('id', $validated['programme_id'])->first();
        if ($programme === null || $programme->status !== 'published') {
            return response()->json(['message' => 'Programme is not open for enrolment'], 422);
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
                    'programme_id' => $validated['programme_id'],
                    'upload_id' => $upload->id,
                    'status' => EnrolmentBatch::STATUS_SCANNING,
                    'created_by' => $admin->id,
                ]);
                $this->audit->record(
                    entityType: 'enrolment_batch',
                    entityId: $batch->id,
                    action: 'batch.created',
                    toState: EnrolmentBatch::STATUS_SCANNING,
                    payloadAfter: ['school_id' => $validated['school_id'], 'programme_id' => $validated['programme_id'], 'upload_id' => $upload->id],
                    actor: $admin,
                );
                ValidateEnrolmentBatch::dispatch($batch->id);

                return $batch;
            },
        );

        return response()->json(['batch_id' => $batch->id, 'status' => $batch->status], 202);
    }

    /**
     * S04E STEP 3 — the batch dashboard (H4), "where are my batches". Lists the
     * school's batches by status/programme/age; the Exceptions view is simply the
     * `failed` batches (D-13 — the batch status IS the FR066 ledger). RLS scopes
     * it: another school's admin sees zero.
     */
    public function index(Request $request): JsonResponse
    {
        $batches = EnrolmentBatch::query()->orderByDesc('created_at')->get([
            'id', 'school_id', 'programme_id', 'status', 'failure_reason',
            'total_rows', 'enrolled_count', 'not_enrolled_count', 'skipped_count', 'failed_count',
            'created_at', 'committed_at',
        ]);

        return response()->json([
            'batches' => $batches->map(fn ($b) => [
                'batch_id' => $b->id,
                'programme_id' => $b->programme_id,
                'status' => $b->status,
                'failure_reason' => $b->failure_reason,
                'age_days' => (int) now()->diffInDays($b->created_at, absolute: true),
                'counts' => [
                    'total' => $b->total_rows, 'enrolled' => $b->enrolled_count,
                    'not_enrolled' => $b->not_enrolled_count, 'skipped' => $b->skipped_count, 'failed' => $b->failed_count,
                ],
            ]),
            // the FR066 ledger (D-13): failed batches are the actionable exceptions
            'exceptions' => $batches->where('status', EnrolmentBatch::STATUS_FAILED)
                ->map(fn ($b) => ['batch_id' => $b->id, 'reason' => $b->failure_reason, 'age_days' => (int) now()->diffInDays($b->created_at, absolute: true)])
                ->values(),
        ]);
    }

    /**
     * The dry-run / post-commit report — batch status, counts, and every row's
     * disposition/outcome/reason. Enrolled rows are ENRICHED with their LIVE
     * enrolment status (a second source — the current enrolment state — not a
     * re-derivation of the stored disposition). RLS scopes it: an admin of
     * another school gets 404 (five-branch).
     */
    public function show(Request $request, string $batch): JsonResponse
    {
        $model = EnrolmentBatch::query()->find($batch);
        if ($model === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $rows = $model->rows()->orderBy('row_number')->get([
            'row_number', 'name', 'email', 'status', 'disposition', 'reason', 'matched_user_id', 'enrolment_id', 'committed',
        ]);
        // Live enrolment status for enrolled rows — read under a system elevation
        // (the student's enrolment is outside the school admin's derived scope; the
        // dashboard shows only the STATUS enum, never consent content).
        $statuses = $this->enrolmentStatuses($rows->pluck('enrolment_id')->filter()->all());
        $rows->each(fn ($r) => $r->enrolment_status = $r->enrolment_id ? ($statuses[$r->enrolment_id] ?? null) : null);

        return response()->json([
            'batch_id' => $model->id,
            'status' => $model->status,
            'programme_id' => $model->programme_id,
            'failure_reason' => $model->failure_reason,
            'counts' => [
                'total' => $model->total_rows,
                'new' => $model->new_count,
                'existing' => $model->existing_count,
                'enrolled' => $model->enrolled_count,
                'not_enrolled' => $model->not_enrolled_count,
                'skipped' => $model->skipped_count,
                'failed' => $model->failed_count,
            ],
            'rows' => $rows,
            // the actionable join-back to S04D — children awaiting a guardian
            'not_enrolled' => $rows->where('status', 'not_enrolled')->map(fn ($r) => ['name' => $r->name, 'email' => $r->email, 'reason' => $r->reason])->values(),
        ]);
    }

    /**
     * @param  list<string>  $enrolmentIds
     * @return array<string,string>  enrolment_id → live status
     */
    private function enrolmentStatuses(array $enrolmentIds): array
    {
        if ($enrolmentIds === []) {
            return [];
        }

        return $this->scope->asSystem(
            'S04E STEP 3 dashboard enrichment (Spec Part H H4): reads the LIVE status of this batch\'s own enrolments (status enum only, no consent content) so the school sees how far each bulk-enrolled child has progressed. The enrolments are outside the school admin\'s derived scope; the read is confined to this batch\'s enrolment ids.',
            fn (): array => DB::table('enrolments')->whereIn('id', $enrolmentIds)->pluck('status', 'id')->all(),
        );
    }

    /**
     * S04E STEP 2 — commit the validated batch. Intent only (OD-31): reuses
     * EnrolmentService::create per row for students with an active guardian;
     * guardian-less rows land not_enrolled. Idempotent (DB unique + committed
     * flag) — a re-commit is a clean no-op that re-evaluates guardian eligibility.
     */
    public function commit(Request $request, string $batch, EnrolmentBatchCommitService $committer): JsonResponse
    {
        $model = EnrolmentBatch::query()->find($batch);
        if ($model === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! in_array($model->status, [
            EnrolmentBatch::STATUS_READY,
            EnrolmentBatch::STATUS_PARTIALLY_COMPLETE,   // re-commit to pick up newly-guardianed rows
            EnrolmentBatch::STATUS_COMPLETE,             // re-commit is an idempotent no-op
        ], true)) {
            return response()->json(['message' => "Batch is not committable (status: {$model->status})"], 409);
        }

        $report = $committer->commit($model, $request->user());

        return response()->json(['batch_id' => $model->id, 'status' => $model->fresh()->status, 'counts' => $report], 200);
    }
}
