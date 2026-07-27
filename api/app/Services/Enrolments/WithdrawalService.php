<?php

namespace App\Services\Enrolments;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Withdrawal workflow (BI-7): request → (pastoral endorsement records, 2.29)
 * → academy-operations decision (OD-26, FIXED) → system job applies the
 * transition. Conflicting guardian actions are REFERRED, never auto-executed
 * (OD-6). Money (full refund only, OD-48) is S04B; team effects are S05.
 */
class WithdrawalService
{
    public function __construct(private readonly AuditService $audit) {}

    public function request(string $enrolmentId, string $reason, User $guardian): object
    {
        $enrolment = DB::table('enrolments')->where('id', $enrolmentId)->first() ?? abort(404);
        if (in_array($enrolment->status, ['completed', 'withdrawn', 'released'], true)) {
            // OD-65: Completed is terminal — no workflow reaches it
            abort(409, "Enrolment is {$enrolment->status}; withdrawal is not available");
        }
        $existing = DB::table('withdrawal_requests')->where('enrolment_id', $enrolmentId)
            ->where('status', 'pending')->first();
        if ($existing !== null) {
            return $existing; // idempotent
        }
        $id = (string) Str::uuid7();
        try {
            DB::table('withdrawal_requests')->insert([
                'id' => $id, 'enrolment_id' => $enrolmentId, 'student_id' => $enrolment->student_id,
                'requested_by' => $guardian->id, 'reason' => $reason, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return DB::table('withdrawal_requests')->where('enrolment_id', $enrolmentId)->where('status', 'pending')->first();
        }
        $this->audit->record('withdrawal_request', $id, 'withdrawal.requested',
            toState: 'pending', reason: $reason, programmeId: (int) $enrolment->programme_id, actor: $guardian);

        return DB::table('withdrawal_requests')->where('id', $id)->first();
    }

    /** Cancel: the REQUESTER alone. Another guardian objecting = a CONFLICT — referred (OD-6). */
    public function cancel(string $requestId, User $guardian): void
    {
        $request = DB::table('withdrawal_requests')->where('id', $requestId)->first() ?? abort(404);
        if ($request->status !== 'pending') {
            abort(409, 'Only a pending request can be cancelled');
        }
        if ((int) $request->requested_by !== (int) $guardian->id) {
            $this->audit->record('withdrawal_request', $requestId, 'withdrawal.conflict_referred',
                reason: 'a different guardian attempted to cancel — conflicting guardian actions are referred to the academy, never auto-executed (OD-6)',
                actor: $guardian);
            abort(409, 'Conflicting guardian action — referred to the academy (OD-6)');
        }
        DB::table('withdrawal_requests')->where('id', $requestId)->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->audit->record('withdrawal_request', $requestId, 'withdrawal.cancelled',
            fromState: 'pending', toState: 'cancelled', actor: $guardian);
    }

    /** Pastoral endorsement (2.29): a RECORD, never authority. */
    public function endorse(string $requestId, string $comment, User $endorser): void
    {
        $request = DB::table('withdrawal_requests')->where('id', $requestId)->first() ?? abort(404);
        DB::table('withdrawal_endorsements')->insert([
            'id' => (string) Str::uuid7(), 'withdrawal_request_id' => $requestId,
            'endorser_id' => $endorser->id, 'endorser_role' => $endorser->role,
            'comment' => $comment, 'created_at' => now(),
        ]);
        $this->audit->record('withdrawal_request', $requestId, 'withdrawal.endorsed',
            reason: $comment, actor: $endorser);
    }

    /** Decision: academy operations, FIXED (OD-26). The transition itself is a system job. */
    public function decide(string $requestId, bool $approve, ?string $decisionReason, User $ops): void
    {
        $request = DB::table('withdrawal_requests')->where('id', $requestId)->first() ?? abort(404);
        if ($request->status !== 'pending') {
            abort(409, 'Request already decided');
        }
        $to = $approve ? 'approved' : 'rejected';
        DB::table('withdrawal_requests')->where('id', $requestId)->update([
            'status' => $to, 'decided_by' => $ops->id, 'decided_at' => now(),
            'decision_reason' => $decisionReason, 'updated_at' => now(),
        ]);
        $this->audit->record('withdrawal_request', $requestId, "withdrawal.{$to}",
            fromState: 'pending', toState: $to, reason: $decisionReason, actor: $ops);
        if ($approve) {
            \App\Jobs\ApplyWithdrawal::dispatch($requestId, (int) $ops->id)->afterCommit();
        }
        \App\Events\WithdrawalDecided::dispatch($requestId, $to); // OD-66; S09 delivers
    }
}
