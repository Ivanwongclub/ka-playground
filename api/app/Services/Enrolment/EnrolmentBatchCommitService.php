<?php

namespace App\Services\Enrolment;

use App\Models\EnrolmentBatch;
use App\Models\EnrolmentBatchRow;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Identity\BulkStudentCreationService;
use Illuminate\Support\Facades\DB;

/**
 * S04E STEP 2 — commit a validated batch (Spec Part H, H2/H3). Intent only
 * (OD-31): no seats, no waitlist, no orders. Reuses the EXISTING primitives so
 * a bulk-enrolled student is indistinguishable downstream from an individually
 * enrolled one:
 *   - `new` rows          → BulkStudentCreationService::create() (mint + roll link)
 *   - rows with an active guardian → EnrolmentService::create() (submitted →
 *     auto-issues consent → pending_consent)
 *   - guardian-less rows  → not_enrolled: awaiting guardian & consent (D-8, P4)
 *
 * Everything is re-evaluated LIVE at commit — the frozen dry-run disposition is
 * a preview, not the verdict. A student who gained a guardian since preview
 * enrols; one who lost one does not.
 *
 * Idempotency is the DATABASE's job, not check-then-act: the per-row `committed`
 * flag skips already-enrolled rows, and EnrolmentService::create() leans on the
 * `enrolments (student_id, programme_id)` partial-unique index — a concurrent
 * or repeated commit returns the ORIGINAL enrolment, never a second. So a
 * double-click/retry is a clean no-op: no duplicate accounts, no duplicate
 * enrolments.
 */
class EnrolmentBatchCommitService
{
    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
        private readonly BulkStudentCreationService $bulk,
        private readonly EnrolmentService $enrolments,
    ) {}

    /**
     * @return array{enrolled:int,not_enrolled:int,skipped:int,failed:int,total:int}
     */
    public function commit(EnrolmentBatch $batch, User $admin): array
    {
        return $this->scope->asSystem(
            'S04E STEP 2 batch commit (Spec Part H / OD-31): drives the existing enrolment machinery row-by-row from a validated batch. The batch/rows/enrolments are a school-wide operation outside any single actor\'s derived scope; enrolment inserts are system-context here (the school admin is not the student\'s guardian). Re-evaluates guardian eligibility LIVE; reuses BulkStudentCreationService::create + EnrolmentService::create; DRY-of-orders (intent only, OD-31).',
            fn (): array => $this->commitInSystem($batch, $admin),
        );
    }

    private function commitInSystem(EnrolmentBatch $batch, User $admin): array
    {
        $programme = $batch->programme_id
            ? DB::table('programmes')->where('id', $batch->programme_id)->first()
            : null;
        if ($programme === null || $programme->status !== 'published') {
            // batch-level failure (FR066) — nothing to enrol into
            $this->failBatch($batch, $admin, 'programme is not open for enrolment');

            throw new \RuntimeException('batch commit refused: programme not open');
        }

        $batch->update(['status' => EnrolmentBatch::STATUS_COMMITTING]);

        $rows = EnrolmentBatchRow::query()
            ->where('batch_id', $batch->id)
            ->where('committed', false)
            ->where('status', EnrolmentBatchRow::STATUS_VALIDATED)
            ->orderBy('row_number')->get();

        foreach ($rows as $row) {
            $this->commitRow($row, $batch, $admin);
        }

        return $this->finalise($batch, $admin);
    }

    private function commitRow(EnrolmentBatchRow $row, EnrolmentBatch $batch, User $admin): void
    {
        $email = strtolower((string) $row->email);

        // LIVE re-evaluation — never trust the stale disposition.
        $user = DB::table('users')->where('email', $email)->first(['id']);
        if ($user === null) {
            // a `new` row: mint the account now (reused engine; idempotent by email)
            $report = $this->bulk->create($admin, [[
                'name' => $row->name, 'email' => $email, 'school_id' => $batch->school_id,
            ]]);
            if ($report['created'] !== []) {
                $user = (object) ['id' => $report['created'][0]['student_id']];
            } elseif ($report['skipped'] !== []) {
                $user = DB::table('users')->where('email', $email)->first(['id']);
            } else {
                $this->markRow($row, EnrolmentBatchRow::STATUS_FAILED, 'account creation failed: '.($report['rejected'][0]['reason'] ?? 'unknown'));

                return;
            }
        }

        // Guardian eligibility — LIVE at commit (D-8): an active guardian must exist.
        $guardianId = DB::table('guardian_links')
            ->where('student_id', $user->id)->where('status', 'active')
            ->orderBy('created_at')->value('guardian_id');
        if ($guardianId === null) {
            $this->markRow($row, EnrolmentBatchRow::STATUS_NOT_ENROLLED, 'awaiting guardian & consent', matchedUserId: (int) $user->id);

            return;
        }

        $guardian = User::query()->find($guardianId);
        // Reuse the EXISTING service — submitted → auto-issues consent → pending_consent.
        // Idempotency is the DB unique (student_id, programme_id): a repeat returns the original.
        $enrolment = $this->enrolments->create((int) $batch->programme_id, (int) $user->id, $guardian);

        $row->update([
            'status' => EnrolmentBatchRow::STATUS_ENROLLED,
            'disposition' => EnrolmentBatchRow::DISPOSITION_EXISTING,
            'matched_user_id' => $user->id,
            'enrolment_id' => $enrolment->id,
            'reason' => null,
            'committed' => true,
        ]);
    }

    private function markRow(EnrolmentBatchRow $row, string $status, string $reason, ?int $matchedUserId = null): void
    {
        $row->update([
            'status' => $status,
            'reason' => $reason,
            'matched_user_id' => $matchedUserId ?? $row->matched_user_id,
            // not_enrolled stays re-evaluable (committed=false); failed is terminal for this file
            'committed' => $status === EnrolmentBatchRow::STATUS_FAILED,
        ]);
    }

    private function finalise(EnrolmentBatch $batch, User $admin): array
    {
        $counts = EnrolmentBatchRow::query()->where('batch_id', $batch->id)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $enrolled = (int) ($counts[EnrolmentBatchRow::STATUS_ENROLLED] ?? 0);
        $notEnrolled = (int) ($counts[EnrolmentBatchRow::STATUS_NOT_ENROLLED] ?? 0);
        $skipped = (int) ($counts[EnrolmentBatchRow::STATUS_SKIPPED] ?? 0);
        $failed = (int) ($counts[EnrolmentBatchRow::STATUS_FAILED] ?? 0);
        $total = (int) $batch->total_rows;

        $status = $enrolled === $total
            ? EnrolmentBatch::STATUS_COMPLETE
            : EnrolmentBatch::STATUS_PARTIALLY_COMPLETE;

        $batch->update([
            'status' => $status,
            'enrolled_count' => $enrolled,
            'not_enrolled_count' => $notEnrolled,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'committed_at' => now(),
        ]);
        $this->audit->record(
            entityType: 'enrolment_batch',
            entityId: $batch->id,
            action: 'batch.committed',
            fromState: EnrolmentBatch::STATUS_COMMITTING,
            toState: $status,
            payloadAfter: compact('enrolled', 'notEnrolled', 'skipped', 'failed'),
            actor: $admin,
        );

        return ['enrolled' => $enrolled, 'not_enrolled' => $notEnrolled, 'skipped' => $skipped, 'failed' => $failed, 'total' => $total];
    }

    private function failBatch(EnrolmentBatch $batch, User $admin, string $reason): void
    {
        $from = $batch->status;
        $batch->update(['status' => EnrolmentBatch::STATUS_FAILED, 'failure_reason' => $reason]);
        $this->audit->record(
            entityType: 'enrolment_batch',
            entityId: $batch->id,
            action: 'batch.failed',
            fromState: $from,
            toState: EnrolmentBatch::STATUS_FAILED,
            reason: $reason,
            actor: $admin,
        );
    }
}
