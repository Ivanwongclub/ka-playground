<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Consent\ConsentSigningService;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Team Formation — the concurrency core (S05-2). One DB transaction, LOCKS AND STATE ONLY,
 * issuance AFTER commit. The whole transaction runs under a system elevation
 * (the state machine writes as system; the approver's authority is established
 * BEFORE the elevation). BINDING LOCK ORDER, every path:
 *   FIRST  — FOR SHARE on the members' signed consent_requests (ORDER BY id),
 *            and (requires_all_guardians) their active guardian_links (ORDER BY id);
 *   SECOND — FOR UPDATE on the programme_capacity row.
 * This order is the only guard against the supersede↔Team Formation / team↔team deadlock.
 */
class TeamConfirmationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly EnrolmentService $enrolments,
        private readonly ConsentSigningService $consent,
        private readonly \App\Services\Money\PayerResolver $payer,
    ) {}

    /** Submit a forming team for approval (the submitter, a student). */
    public function submit(string $teamId, User $student): void
    {
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        if ((int) $team->created_by !== (int) $student->id) {
            abort(403, 'Only the team submitter may submit it');
        }
        if ($team->status !== 'forming') {
            abort(409, "Team is {$team->status}");
        }
        $this->scope->asSystem(
            'Team submit transition (S05): the submitter moves their own forming team to submitted; teams.status is a system-only write (S04A state-machine discipline), the submitter authority was just checked.',
            fn () => DB::table('teams')->where('id', $teamId)->update(['status' => 'submitted', 'updated_at' => now()]),
        );
        $this->audit->record('team', $teamId, 'team.submitted', fromState: 'forming', toState: 'submitted',
            programmeId: (int) $team->programme_id, actor: $student);
    }

    /** Team Formation — approve a submitted team. Authority checked in the approver's context; the transaction runs as system. */
    public function confirm(string $teamId, User $approver): array
    {
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        if ($team->status !== 'submitted') {
            abort(409, "Team is {$team->status}; only a submitted team can be 成團-confirmed");
        }
        // 0. hard precondition: formation deadline set (OD-33; S04A warns, S05 refuses)
        $deadline = json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $team->programme_id)->where('section_key', 'team_rules')
            ->value('data'), true)['formation_deadline_on'] ?? null;
        if ($deadline === null) {
            abort(422, 'No formation deadline set for this programme — 成團 refused (OD-33)');
        }
        // 1. approver authority (OD-39): school admin of the lobby's school, or academy ops/super
        $this->assertApprover($team, $approver);
        // capacity must be configured (S05-2 hard requirement at Team Formation)
        if (! DB::table('programme_capacity')->where('programme_id', $team->programme_id)->exists()) {
            abort(422, 'Programme capacity is not configured — 成團 refused (OD-31)');
        }

        return $this->scope->asSystem(
            'Team 成團 confirmation (S05): the whole seat-claim transaction is a system state-machine op — FOR SHARE on members\' consent (+guardian_links), FOR UPDATE on programme_capacity, teamed→confirmed, one payment_obligation per member. The approver\'s authority (OD-39) was established before the elevation; only the members\' own rows are touched.',
            function () use ($team, $approver): array {
                return DB::transaction(function () use ($team, $approver): array {
                    $members = DB::table('team_members')->where('team_id', $team->id)->where('status', 'active')
                        ->orderBy('id')->get();
                    if ($members->isEmpty()) {
                        abort(422, 'Team has no active members');
                    }
                    $studentIds = $members->pluck('student_id')->map(fn ($x) => (int) $x)->all();
                    $requiresAll = (bool) (json_decode((string) DB::table('wizard_sections')
                        ->where('programme_id', $team->programme_id)->where('section_key', 'consent')
                        ->value('data'), true)['requires_all_guardians'] ?? false);

                    // ── FIRST LOCK SLOT — consent (ORDER BY id), then guardian_links ──
                    DB::select('SELECT id FROM consent_requests
                        WHERE programme_id = ? AND student_id = ANY(?) AND status = ?
                        ORDER BY id FOR SHARE', [$team->programme_id, '{'.implode(',', $studentIds).'}', 'signed']);
                    if ($requiresAll) {
                        DB::select('SELECT id FROM guardian_links
                            WHERE student_id = ANY(?) AND status = ? ORDER BY id FOR SHARE',
                            ['{'.implode(',', $studentIds).'}', 'active']);
                    }
                    // re-verify consent per member UNDER the lock (OD-57/58 — no stale confirm)
                    foreach ($members as $m) {
                        if (! $this->consent->consentSatisfied((int) $team->programme_id, (int) $m->student_id)) {
                            abort(422, "Member {$m->student_id} consent is not satisfied or is stale — 成團 refused (OD-57/58)");
                        }
                    }

                    // ── SECOND LOCK SLOT — capacity FOR UPDATE, check AND increment in one hold ──
                    $n = $members->count();
                    $cap = DB::selectOne('SELECT capacity, claimed FROM programme_capacity WHERE programme_id = ? FOR UPDATE', [$team->programme_id]);
                    if ((int) $cap->claimed + $n > (int) $cap->capacity) {
                        abort(409, "Insufficient capacity: {$cap->claimed}/{$cap->capacity} claimed, this team needs {$n} — 成團 refused, no partial claim (OD-32)");
                    }
                    DB::update('UPDATE programme_capacity SET claimed = claimed + ?, updated_at = now() WHERE programme_id = ?', [$n, $team->programme_id]);

                    // teamed → confirmed + one obligation per member; payer resolved from the
                    // programme's E6 payer_party (S04F STEP 1 / OD-25) — NOT hardcoded guardian.
                    $obligationIds = [];
                    foreach ($members as $m) {
                        $this->enrolments->transition($m->enrolment_id, 'confirmed', $approver, "成團 of team {$team->id}");
                        // OD-38 "no re-charge": a dissolution re-pools PAID members into the
                        // pool keeping their paid order. When such a member re-teams, they
                        // claim a seat and confirm, but get NO second obligation — otherwise
                        // the outbox would re-request payment against their live paid order.
                        if (DB::table('orders')->where('enrolment_id', $m->enrolment_id)->where('status', 'paid')->exists()) {
                            continue;
                        }
                        // Loud on an unresolvable school payer — never a silent guardian fallback (D-18).
                        $payer = $this->payer->resolve((int) $team->programme_id, (int) $m->student_id);
                        $oid = (string) Str::uuid7();
                        DB::table('payment_obligations')->insert([
                            'id' => $oid, 'enrolment_id' => $m->enrolment_id, 'programme_id' => $team->programme_id,
                            'student_id' => $m->student_id, 'payer_party' => $payer['payer_party'], 'payer_school_id' => $payer['payer_school_id'],
                            'created_at' => now(),
                        ]);
                        $obligationIds[] = $oid;
                    }
                    DB::table('teams')->where('id', $team->id)->update(['status' => 'confirmed', 'updated_at' => now()]);
                    $this->audit->record('team', $team->id, 'team.confirmed',
                        fromState: 'submitted', toState: 'confirmed', programmeId: (int) $team->programme_id,
                        payloadAfter: ['seats_claimed' => $n, 'member_count' => $n, 'approver' => $approver->id], actor: $approver);

                    return ['team_id' => $team->id, 'seats_claimed' => $n, 'obligations' => $obligationIds];
                });
            },
        );
    }

    /** OD-39 authority (single source): lobby school-admin of the team's category, or academy ops/super.
     *  Public so the S-UX3-3a consent-status read reuses the SAME gate as the Team Formation confirm. */
    public function assertApprover(object $team, User $approver): void
    {
        // academy operations / super always
        if ($approver->role === 'academy_admin') {
            $caps = DB::table('admin_capabilities')->where('user_id', $approver->id)->pluck('capability');
            if ($caps->contains('operations') || $caps->contains('super_admin')) {
                return;
            }
        }
        // school admin of the lobby's school (OD-39: school approves its own lobby's teams)
        if ($approver->role === 'school_admin') {
            $schoolId = DB::table('team_categories')->where('id', $team->category_id)->value('school_id');
            if ($schoolId !== null && DB::table('school_admin_links')
                ->where('school_admin_id', $approver->id)->where('school_id', $schoolId)->where('status', 'active')->exists()) {
                return;
            }
        }
        abort(403, 'You are not authorised to approve this team (OD-39: lobby school admin or academy operations)');
    }
}
