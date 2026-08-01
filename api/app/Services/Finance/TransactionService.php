<?php

namespace App\Services\Finance;

use App\Models\TeamTransaction;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Teams\TrackerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S07 STEP 2 (FR061 · Spec §P1) — team-project transactions + verification, the
 * SoD core. ONE guarded transition path (system-context, every edge audited);
 * touches only team_transactions/budget_lines (record-only, WHOLLY SEPARATE from
 * the Order module — §A3/GR006).
 *
 *  - Evidence-before-Submitted: `submit` requires a clean evidence upload, so
 *    Verified-without-evidence is structurally impossible (plus the DB CHECK).
 *  - NEW SoD: `verify` refuses when the verifier is the recorder — app 403 +
 *    the tt_sod_check CHECK constraint (D-16; BI-9 pattern, new table).
 *  - Immutable once recorded (BI-5, DB trigger).
 *  - Over-budget = FLAG, not block (D-B5): approval past a line's planned amount
 *    requires the approver to ACKNOWLEDGE — informed approval, never under-recorded.
 */
class TransactionService
{
    private const TRANSITIONS = [
        TeamTransaction::DRAFT => [TeamTransaction::RECEIPT_ATTACHED],
        TeamTransaction::RECEIPT_ATTACHED => [TeamTransaction::SUBMITTED],
        TeamTransaction::SUBMITTED => [TeamTransaction::UNDER_REVIEW],
        TeamTransaction::UNDER_REVIEW => [TeamTransaction::APPROVED, TeamTransaction::REJECTED],
        TeamTransaction::APPROVED => [TeamTransaction::RECORDED],
        TeamTransaction::RECORDED => [TeamTransaction::VERIFIED],
        TeamTransaction::REJECTED => [],
        TeamTransaction::VERIFIED => [],
    ];

    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
        private readonly TrackerService $tracker,
    ) {}

    /** @param  array{type:string,amount_minor:int,budget_line_id:?string,beneficiary_member_id:?int,description:string,occurred_on:string}  $data */
    public function record(string $teamId, array $data, User $actor): TeamTransaction
    {
        return $this->elevated(function () use ($teamId, $data, $actor): TeamTransaction {
            $this->assertTeamMember($teamId, $actor);
            if ($data['budget_line_id'] !== null && ! DB::table('budget_lines')->where('id', $data['budget_line_id'])->where('team_id', $teamId)->exists()) {
                throw ValidationException::withMessages(['budget_line_id' => ['That budget line does not belong to this team']]);
            }
            $txn = TeamTransaction::query()->create([
                'id' => (string) Str::uuid7(), 'team_id' => $teamId, 'type' => $data['type'],
                'amount_minor' => $data['amount_minor'], 'currency' => 'HKD',
                'budget_line_id' => $data['budget_line_id'], 'beneficiary_member_id' => $data['beneficiary_member_id'],
                'description' => $data['description'], 'occurred_on' => $data['occurred_on'],
                'status' => TeamTransaction::DRAFT, 'recorded_by' => $actor->id,
            ]);
            $this->audit->record('team_transaction', $txn->id, 'team_transaction.draft', toState: TeamTransaction::DRAFT,
                payloadAfter: ['team_id' => $teamId, 'type' => $data['type'], 'amount_minor' => $data['amount_minor']], actor: $actor);

            return $txn;
        });
    }

    public function attachEvidence(string $txnId, string $uploadId, User $actor): void
    {
        $this->elevated(function () use ($txnId, $uploadId, $actor): void {
            $txn = $this->load($txnId);
            $this->assertTeamMember($txn->team_id, $actor);
            $upload = DB::table('uploads')->where('id', $uploadId)->where('context', 'evidence')->first();
            if ($upload === null) {
                throw ValidationException::withMessages(['evidence' => ['No such evidence upload']]);
            }
            DB::table('team_transactions')->where('id', $txnId)->update(['evidence_upload_id' => $uploadId]);
            $this->transition($txnId, TeamTransaction::RECEIPT_ATTACHED, $actor, 'receipt attached');
        });
    }

    public function submit(string $txnId, User $actor): void
    {
        $this->elevated(function () use ($txnId, $actor): void {
            $txn = $this->load($txnId);
            $this->assertTeamMember($txn->team_id, $actor);
            // BI-10 gate: evidence must be scan-clean before a transaction can advance.
            $clean = DB::table('uploads')->where('id', $txn->evidence_upload_id)->where('status', 'clean')->exists();
            if (! $clean) {
                abort(409, 'Evidence is not yet scan-clean — submission waits for the scan (BI-10)');
            }
            $this->transition($txnId, TeamTransaction::SUBMITTED, $actor, 'submitted for approval');
        });
    }

    /** Teacher approves → recorded (financial facts frozen). Over-budget requires acknowledgement (D-B5). */
    public function approve(string $txnId, User $approver, bool $overBudgetAck = false): void
    {
        $this->elevated(function () use ($txnId, $approver, $overBudgetAck): void {
            $txn = $this->load($txnId);
            $this->assertApprover($txn->team_id, $approver);
            $over = $this->wouldOverspend($txn);
            if ($over && ! $overBudgetAck) {
                abort(422, 'This expense exceeds its budget line — acknowledge the over-budget to approve (D-B5: recorded, not blocked)');
            }
            $this->transition($txnId, TeamTransaction::UNDER_REVIEW, $approver, 'review opened');
            $this->transition($txnId, TeamTransaction::APPROVED, $approver, 'approved'.($over ? ' (over budget, acknowledged)' : ''));
            DB::table('team_transactions')->where('id', $txnId)->update(['over_budget_acknowledged' => $over, 'recorded_at' => now()]);
            $this->transition($txnId, TeamTransaction::RECORDED, $approver, 'recorded to the ledger');
        });
    }

    public function reject(string $txnId, User $approver, ?string $notes = null): void
    {
        $this->elevated(function () use ($txnId, $approver, $notes): void {
            $this->assertApprover($this->load($txnId)->team_id, $approver);
            $this->transition($txnId, TeamTransaction::UNDER_REVIEW, $approver, 'review opened');
            $this->transition($txnId, TeamTransaction::REJECTED, $approver, $notes ?? 'rejected');
        });
    }

    /** The SoD step: a verifier OTHER than the recorder confirms against offline reality. */
    public function verify(string $txnId, User $verifier): void
    {
        $this->elevated(function () use ($txnId, $verifier): void {
            $txn = $this->load($txnId);
            $this->assertVerifier($txn->team_id, $verifier);
            if ((int) $txn->recorded_by === (int) $verifier->id) {
                // D-16 SoD, refused server-side and audited — the tt_sod_check CHECK backstops this
                $this->audit->record('team_transaction', $txnId, 'team_transaction.verify_refused',
                    reason: 'recorder attempted to verify their own transaction (SoD: verifier ≠ recorder)', actor: $verifier);
                abort(403, 'SoD: the recorder cannot verify their own transaction — a second person must verify');
            }
            DB::table('team_transactions')->where('id', $txnId)->update(['verified_by' => $verifier->id, 'verified_at' => now()]);
            $this->transition($txnId, TeamTransaction::VERIFIED, $verifier, 'verified against offline reality');
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function wouldOverspend(object $txn): bool
    {
        // only an expense against a budget line can overspend
        if ($txn->type !== 'expense' || $txn->budget_line_id === null) {
            return false;
        }
        $planned = (int) DB::table('budget_lines')->where('id', $txn->budget_line_id)->value('planned_amount_minor');
        $already = (int) DB::table('team_transactions')
            ->where('budget_line_id', $txn->budget_line_id)->where('type', 'expense')
            ->whereIn('status', [TeamTransaction::RECORDED, TeamTransaction::VERIFIED])
            ->sum('amount_minor');

        return $already + (int) $txn->amount_minor > $planned;
    }

    private function transition(string $txnId, string $to, User $actor, string $reason): void
    {
        $txn = $this->load($txnId);
        if ($txn->status === $to) {
            return;
        }
        if (! in_array($to, self::TRANSITIONS[$txn->status] ?? [], true)) {
            throw new \RuntimeException("Illegal transaction transition {$txn->status} → {$to}");
        }
        DB::table('team_transactions')->where('id', $txnId)->update(['status' => $to, 'updated_at' => now()]);
        $this->audit->record('team_transaction', $txnId, "team_transaction.{$to}",
            fromState: $txn->status, toState: $to, reason: $reason, payloadAfter: ['team_id' => $txn->team_id], actor: $actor);
    }

    private function load(string $txnId): object
    {
        return DB::table('team_transactions')->where('id', $txnId)->first()
            ?? throw new \RuntimeException("Team transaction {$txnId} not found");
    }

    private function assertTeamMember(string $teamId, User $actor): void
    {
        if (! DB::table('team_members')->where('team_id', $teamId)->where('student_id', $actor->id)->where('status', 'active')->exists()) {
            abort(403, 'Only an active member of the team may record its transactions');
        }
    }

    /** Verification is a second pair of eyes: an active member OR the team's teacher (the SoD is the gate). */
    private function assertVerifier(string $teamId, User $verifier): void
    {
        $member = DB::table('team_members')->where('team_id', $teamId)->where('student_id', $verifier->id)->where('status', 'active')->exists();
        if ($member) {
            return;
        }
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        $this->tracker->approverKindFor($verifier, $team); // teacher/school-admin/academy — throws 403 otherwise
    }

    private function assertApprover(string $teamId, User $approver): void
    {
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        $this->tracker->approverKindFor($approver, $team);
    }

    private function elevated(\Closure $fn): mixed
    {
        return $this->scope->asSystem(
            'S07 STEP 2 team transaction lifecycle (FR061 · Spec §P1): the whole transaction state machine is a system-context op on team_transactions (system-write by construction, RECORD-ONLY, WHOLLY SEPARATE from the Order module per §A3/GR006). Actor authority — team membership to record, the reused S05 gate authority to approve, a second person to verify — is checked before the elevation; the recorder≠verifier SoD is enforced here AND by the tt_sod_check CHECK; every transition is audited.',
            $fn,
        );
    }
}
