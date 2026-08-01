<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S05-4 — the four TERMINAL actions on a below-min (OD-37) exception, each of
 * which resolves it (there is deliberately no "leave pending" option, A4):
 *   assign   — place an unplaced student into the team, claiming a fresh seat;
 *   grace    — extend the lapsed member's grace exactly ONCE (OD-37, not a loop);
 *   waive    — grant an under-strength waiver, stored as a FIELD on the team (OD-40);
 *   dissolve — disband the team, re-pooling PAID members in-lobby with paid status
 *              kept and no re-charge (OD-38); unpaid orders are cancelled.
 *
 * Authority is academy operations/super (OD-37 "academy exception"), checked in
 * the admin's context; the state writes run under a system elevation.
 */
class TeamResolutionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly EnrolmentService $enrolments,
        private readonly TeamExceptionService $exceptions,
        private readonly \App\Services\Money\PayerResolver $payer,
    ) {}

    /** ASSIGN — place an unplaced in_pool student into the below-min team, claiming one seat + issuing their obligation. */
    public function assign(string $teamId, string $enrolmentId, User $admin): array
    {
        $this->assertAcademyOperator($admin);

        return $this->scope->asSystem(
            'Below-min resolution — ASSIGN (S05-4, OD-37): an admin places an unplaced student into a below-min team; one seat is claimed under FOR UPDATE, the enrolment moves in_pool → teamed → confirmed, and a payment_obligation is written with the payer resolved from the programme E6 (S04F). Admin authority + lobby eligibility checked before the elevation.',
            function () use ($teamId, $enrolmentId, $admin): array {
                return DB::transaction(function () use ($teamId, $enrolmentId, $admin): array {
                    $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
                    if ($team->status !== 'confirmed') {
                        abort(409, "Team is {$team->status}; assign resolves a below-min CONFIRMED team");
                    }
                    $enrolment = DB::table('enrolments')->where('id', $enrolmentId)->first() ?? abort(404);
                    if ($enrolment->status !== 'in_pool') {
                        abort(422, "Enrolment is {$enrolment->status}, not an unplaced (in_pool) student");
                    }
                    if ((int) $enrolment->programme_id !== (int) $team->programme_id) {
                        abort(422, 'Student and team are in different programmes');
                    }
                    $this->assertLobbyEligible($team, (int) $enrolment->student_id);

                    // claim one seat (BI-3: FOR UPDATE on the counter)
                    $cap = DB::selectOne('SELECT capacity, claimed FROM programme_capacity WHERE programme_id = ? FOR UPDATE', [$team->programme_id]);
                    if ($cap === null) {
                        abort(422, 'Programme capacity is not configured');
                    }
                    if ((int) $cap->claimed + 1 > (int) $cap->capacity) {
                        abort(409, 'No free seat to assign — the suspended member still holds theirs; use waiver or dissolve (OD-37)');
                    }
                    DB::update('UPDATE programme_capacity SET claimed = claimed + 1, updated_at = now() WHERE programme_id = ?', [$team->programme_id]);

                    DB::table('team_members')->insert([
                        'id' => (string) Str::uuid7(), 'team_id' => $team->id, 'enrolment_id' => $enrolment->id,
                        'category_id' => $team->category_id, 'student_id' => $enrolment->student_id,
                        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->enrolments->transition($enrolment->id, 'teamed', $admin, "assigned into team {$team->id} (OD-37)");
                    $this->enrolments->transition($enrolment->id, 'confirmed', $admin, "assigned into team {$team->id} (OD-37)");
                    // OD-38 "no re-charge": an assigned member who already PAID (re-pooled from a
                    // dissolution) claims the seat and confirms, but gets NO second obligation.
                    $oid = null;
                    if (! DB::table('orders')->where('enrolment_id', $enrolment->id)->where('status', 'paid')->exists()) {
                        // payer resolved from the programme E6 (S04F STEP 1) — same helper as 成團,
                        // loud on an unresolvable school payer, never a silent guardian fallback (D-18).
                        $payer = $this->payer->resolve((int) $team->programme_id, (int) $enrolment->student_id);
                        $oid = (string) Str::uuid7();
                        DB::table('payment_obligations')->insert([
                            'id' => $oid, 'enrolment_id' => $enrolment->id, 'programme_id' => $team->programme_id,
                            'student_id' => $enrolment->student_id, 'payer_party' => $payer['payer_party'], 'payer_school_id' => $payer['payer_school_id'],
                            'created_at' => now(),
                        ]);
                    }
                    $this->exceptions->resolveOpenFor((int) $team->programme_id, 'team', $team->id, 'assigned', by: $admin, type: 'below_min');
                    $this->audit->record('team', $team->id, 'team.member_assigned',
                        programmeId: (int) $team->programme_id, reason: 'below-min resolution (OD-37)',
                        payloadAfter: ['enrolment_id' => $enrolment->id, 'obligation_id' => $oid], actor: $admin);

                    return ['team_id' => $team->id, 'obligation_id' => $oid];
                });
            },
        );
    }

    /** GRACE — extend the suspended member's grace exactly ONCE and un-suspend them (OD-37). */
    public function extendGrace(string $teamId, string $enrolmentId, User $admin): void
    {
        $this->assertAcademyOperator($admin);
        $graceDays = (int) config('teams.lapse_grace_days', 7);
        $this->scope->asSystem(
            'Below-min resolution — GRACE-ONCE (S05-4, OD-37): an admin extends a suspended member\'s payment grace exactly once and un-suspends them. A second extension is refused (grace is not a loop, OD-31/37). team_members is a system-only write; authority checked before the elevation.',
            function () use ($teamId, $enrolmentId, $admin, $graceDays): void {
                $member = DB::table('team_members')->where('team_id', $teamId)->where('enrolment_id', $enrolmentId)->first() ?? abort(404);
                if ($member->status !== 'suspended') {
                    abort(409, "Member is {$member->status}, not suspended — nothing to extend");
                }
                if ($member->grace_extended) {
                    abort(409, 'Grace has already been extended once — it is not repeatable (OD-37); assign, waive or dissolve');
                }
                $team = DB::table('teams')->where('id', $teamId)->first();
                DB::table('team_members')->where('id', $member->id)->update([
                    'status' => 'active', 'suspended_at' => null,
                    'grace_extended' => true, 'grace_until' => now()->addDays($graceDays), 'updated_at' => now(),
                ]);
                $this->exceptions->resolveOpenFor((int) $team->programme_id, 'enrolment', $enrolmentId, 'grace_extended', by: $admin, type: 'lapse');
                // the member is active again — if the team is back to strength, clear its below-min exception too
                $minSize = (int) ($this->minTeamSize((int) $team->programme_id) ?? 1);
                $active = DB::table('team_members')->where('team_id', $teamId)->where('status', 'active')->count();
                if ($active >= $minSize) {
                    $this->exceptions->resolveOpenFor((int) $team->programme_id, 'team', $teamId, 'grace_extended', by: $admin, type: 'below_min');
                }
                $this->audit->record('team_member', $member->id, 'team_member.grace_extended',
                    fromState: 'suspended', toState: 'active', programmeId: (int) $team->programme_id,
                    payloadAfter: ['enrolment_id' => $enrolmentId, 'grace_days' => $graceDays], actor: $admin);
            },
        );
    }

    /** WAIVE — grant an under-strength waiver, stored as a reason FIELD on the team (OD-40). */
    public function waive(string $teamId, string $reason, User $admin): void
    {
        $this->assertAcademyOperator($admin);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['A waiver requires a written reason (OD-40)']]);
        }
        $this->scope->asSystem(
            'Below-min resolution — WAIVE (S05-4, OD-40): an admin grants an under-strength waiver, stored as a reason field on the team so the nightly size check reads "meets rules OR waiver". teams is a system-only write; authority checked before the elevation.',
            function () use ($teamId, $reason, $admin): void {
                $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
                if ($team->status !== 'confirmed') {
                    abort(409, "Team is {$team->status}; a waiver applies to a confirmed team");
                }
                DB::table('teams')->where('id', $teamId)->update([
                    'waiver_reason' => $reason, 'waived_by' => $admin->id, 'waived_at' => now(), 'updated_at' => now(),
                ]);
                $this->exceptions->resolveOpenFor((int) $team->programme_id, 'team', $teamId, 'waived', by: $admin, type: 'below_min');
                $this->audit->record('team', $teamId, 'team.waived',
                    programmeId: (int) $team->programme_id, reason: $reason,
                    payloadAfter: ['waived_by' => $admin->id], actor: $admin);
            },
        );
    }

    /** DISSOLVE — disband the team, re-pooling PAID members in-lobby (paid kept, no re-charge); cancel unpaid orders (OD-38). */
    public function dissolve(string $teamId, User $admin): array
    {
        $this->assertAcademyOperator($admin);

        return $this->scope->asSystem(
            'Below-min resolution — DISSOLVE (S05-4, OD-38): an admin disbands the team; each live member\'s enrolment moves confirmed → in_pool (re-pooled in-lobby), PAID orders are kept untouched (no re-charge, no refund) and unpaid orders are cancelled, then the team is disbanded. System-only writes; authority checked before the elevation.',
            function () use ($teamId, $admin): array {
                return DB::transaction(function () use ($teamId, $admin): array {
                    $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
                    if ($team->status !== 'confirmed') {
                        abort(409, "Team is {$team->status}; only a confirmed team is dissolved");
                    }
                    $members = DB::table('team_members')->where('team_id', $teamId)->whereIn('status', ['active', 'suspended'])->orderBy('id')->get();
                    $repooled = 0;
                    $seatsFreed = 0;
                    foreach ($members as $m) {
                        $order = DB::table('orders')->where('enrolment_id', $m->enrolment_id)->where('status', '<>', 'cancelled')->first();
                        if ($order !== null && $order->status !== 'paid') {
                            // unpaid → cancel it: no lingering obligation, frees a clean re-team (no double order)
                            DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                            $this->audit->record('order', $order->id, 'order.cancelled',
                                fromState: $order->status, toState: 'cancelled', reason: 'team dissolved, unpaid order voided (OD-38)',
                                programmeId: (int) $team->programme_id, actor: $admin);
                        }
                        // re-pool: confirmed → in_pool, paid order (if any) retained
                        $this->enrolments->transition($m->enrolment_id, 'in_pool', $admin, "team {$teamId} dissolved, re-pooled in-lobby (OD-38)");
                        DB::table('team_members')->where('id', $m->id)->update(['status' => 'removed', 'updated_at' => now()]);
                        $repooled++;
                        $seatsFreed++;
                    }
                    // release the team's seats back to the counter
                    if ($seatsFreed > 0) {
                        DB::selectOne('SELECT claimed FROM programme_capacity WHERE programme_id = ? FOR UPDATE', [$team->programme_id]);
                        DB::update('UPDATE programme_capacity SET claimed = GREATEST(0, claimed - ?), updated_at = now() WHERE programme_id = ?', [$seatsFreed, $team->programme_id]);
                    }
                    DB::table('teams')->where('id', $teamId)->update(['status' => 'disbanded', 'updated_at' => now()]);
                    $this->exceptions->resolveOpenFor((int) $team->programme_id, 'team', $teamId, 'dissolved', by: $admin);
                    $this->audit->record('team', $teamId, 'team.disbanded',
                        fromState: 'confirmed', toState: 'disbanded', programmeId: (int) $team->programme_id,
                        payloadAfter: ['members_repooled' => $repooled, 'seats_freed' => $seatsFreed], actor: $admin);

                    return ['team_id' => $teamId, 'members_repooled' => $repooled];
                });
            },
        );
    }

    /** OD-62 — a student leaves school mid-programme: the team STANDS, an academy exception is raised (never an automatic withdrawal). */
    public function recordSchoolLeave(string $enrolmentId, User $admin): string
    {
        $this->assertAcademyOperator($admin);

        return $this->scope->asSystem(
            'School-leave record (S05-4, OD-62): a student leaves school mid-programme. The team STANDS — no membership or team state change — and an academy school_leave exception is raised. team_exceptions is a system-only write; authority checked before the elevation.',
            function () use ($enrolmentId, $admin): string {
                $member = DB::table('team_members')->where('enrolment_id', $enrolmentId)->whereIn('status', ['active', 'suspended'])->first() ?? abort(404);
                $team = DB::table('teams')->where('id', $member->team_id)->first();
                $id = $this->exceptions->raise((int) $team->programme_id, 'school_leave',
                    teamId: $member->team_id, enrolmentId: $enrolmentId,
                    reason: 'Student left school mid-programme; team stands (OD-62)', by: $admin);
                if ($id === null) {
                    abort(409, 'A school-leave exception is already open for this member');
                }
                $this->audit->record('team_member', $member->id, 'team_member.school_leave',
                    programmeId: (int) $team->programme_id, reason: 'mid-programme school leave (OD-62)',
                    payloadAfter: ['enrolment_id' => $enrolmentId, 'team_id' => $member->team_id, 'team_stands' => true], actor: $admin);

                return $id;
            },
        );
    }

    private function assertAcademyOperator(User $admin): void
    {
        if ($admin->role !== 'academy_admin') {
            abort(403, 'Below-min resolution is an academy action (OD-37)');
        }
        $caps = DB::table('admin_capabilities')->where('user_id', $admin->id)->pluck('capability');
        if (! $caps->contains('operations') && ! $caps->contains('super_admin')) {
            abort(403, 'Below-min resolution requires the operations capability (OD-37)');
        }
    }

    private function assertLobbyEligible(object $team, int $studentId): void
    {
        $schoolId = DB::table('team_categories')->where('id', $team->category_id)->value('school_id');
        if ($schoolId === null) {
            return;
        }
        if (! DB::table('school_links')->where('student_id', $studentId)->where('school_id', $schoolId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['lobby' => ['Student is not eligible for this team\'s lobby (school binding)']]);
        }
    }

    private function minTeamSize(int $programmeId): ?int
    {
        $rules = (array) (json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'team_rules')
            ->value('data'), true) ?? []);

        return isset($rules['min_team_size']) ? (int) $rules['min_team_size'] : null;
    }
}
