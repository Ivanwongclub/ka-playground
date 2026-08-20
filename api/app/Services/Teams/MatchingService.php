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
 * S05 STEP 3 — the deadline matching screen's three actions (OD-35): MATCH an
 * unplaced student into an under-strength team, ROLL (park) them forward with a
 * 90-day auto-refund backstop, or RELEASE them. An admin acts; the authority is
 * checked in the admin's context, then the state writes run under a system
 * elevation (enrolments/teams/team_members are system-only writes).
 *
 * Default backstop window: 90 days (OD-35), overridable per programme via
 * team_rules.parking_backstop_days.
 */
class MatchingService
{
    public const DEFAULT_BACKSTOP_DAYS = 90;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly EnrolmentService $enrolments,
        private readonly TeamExceptionService $exceptions,
    ) {}

    /** Assign an unplaced (in_pool) student into an under-strength forming team in the same lobby. */
    public function match(string $enrolmentId, string $teamId, User $admin): void
    {
        $this->assertAcademyOperator($admin);
        $this->scope->asSystem(
            'Deadline matching — MATCH (S05-3, OD-35): an admin places an unplaced student into an under-strength team; the enrolment moves in_pool → teamed (system-only) and a team_member is inserted. The admin authority and lobby eligibility are checked before the elevation; exactly this one enrolment/team is touched.',
            function () use ($enrolmentId, $teamId, $admin): void {
                $enrolment = DB::table('enrolments')->where('id', $enrolmentId)->first() ?? abort(404);
                if ($enrolment->status !== 'in_pool') {
                    abort(422, "Enrolment is {$enrolment->status}, not an unplaced (in_pool) student");
                }
                $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
                if ((int) $team->programme_id !== (int) $enrolment->programme_id) {
                    abort(422, 'Team and student are in different programmes');
                }
                if ($team->status !== 'forming') {
                    abort(409, "Team is {$team->status}; only a forming team accepts a match");
                }
                $this->assertLobbyEligible($team, (int) $enrolment->student_id);

                DB::table('team_members')->insert([
                    'id' => (string) Str::uuid7(), 'team_id' => $team->id, 'enrolment_id' => $enrolment->id,
                    'programme_id' => $team->programme_id,
                    'category_id' => $team->category_id, 'student_id' => $enrolment->student_id,
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->enrolments->transition($enrolment->id, 'teamed', $admin, "matched into team {$team->id} at deadline (OD-35)");
                $this->audit->record('team', $team->id, 'team.matched',
                    programmeId: (int) $team->programme_id, reason: 'deadline matching (OD-35)',
                    payloadAfter: ['enrolment_id' => $enrolment->id, 'student_id' => $enrolment->student_id], actor: $admin);

                // any open parked/failed exception for this student is resolved by placement
                $this->exceptions->resolveOpenFor((int) $team->programme_id, 'enrolment', $enrolment->id, 'matched', by: $admin);

                // if the team now meets minimum, clear its deadline exception and make it Team Formation-ready
                $minSize = (int) ($this->teamRules((int) $team->programme_id)['min_team_size'] ?? 1);
                $count = DB::table('team_members')->where('team_id', $team->id)->where('status', 'active')->count();
                if ($count >= $minSize) {
                    $this->exceptions->resolveOpenFor((int) $team->programme_id, 'team', $team->id, 'matched', by: $admin, type: 'deadline_noncompliant');
                    DB::table('teams')->where('id', $team->id)->update(['status' => 'submitted', 'updated_at' => now()]);
                    $this->audit->record('team', $team->id, 'team.auto_submitted',
                        fromState: 'forming', toState: 'submitted', reason: 'reached minimum via matching (OD-35)',
                        programmeId: (int) $team->programme_id, payloadAfter: ['member_count' => $count, 'min_size' => $minSize], actor: $admin);
                }
            },
        );
    }

    /** Roll the student forward: park as a pending exception with a backstop_at (OD-35, loop-breaker). */
    public function roll(string $enrolmentId, User $admin): string
    {
        $this->assertAcademyOperator($admin);

        return $this->scope->asSystem(
            'Deadline matching — ROLL (S05-3, OD-35): an admin parks an unplaced student as a pending roll-forward exception with a 90-day auto-refund backstop. The enrolment stays in_pool; only a team_exceptions row is written (system-only). Admin authority checked before the elevation.',
            function () use ($enrolmentId, $admin): string {
                $enrolment = DB::table('enrolments')->where('id', $enrolmentId)->first() ?? abort(404);
                if ($enrolment->status !== 'in_pool') {
                    abort(422, "Enrolment is {$enrolment->status}, not an unplaced (in_pool) student");
                }
                $days = (int) ($this->teamRules((int) $enrolment->programme_id)['parking_backstop_days'] ?? self::DEFAULT_BACKSTOP_DAYS);
                $backstopAt = now()->addDays($days);
                $id = $this->exceptions->raise((int) $enrolment->programme_id, 'parked_rollforward',
                    enrolmentId: $enrolment->id, reason: "Rolled forward at deadline; auto-refund+release backstop in {$days}d (OD-35)",
                    backstopAt: $backstopAt, by: $admin);
                if ($id === null) {
                    abort(409, 'Student is already parked (an open roll-forward exception exists)');
                }

                return $id;
            },
        );
    }

    /** Release the unplaced student: in_pool → released (OD-35). Unpaid only; a paid re-pool is the backstop/dissolution path. */
    public function release(string $enrolmentId, User $admin): void
    {
        $this->assertAcademyOperator($admin);
        $this->scope->asSystem(
            'Deadline matching — RELEASE (S05-3, OD-35): an admin releases an unplaced student; the enrolment moves in_pool → released (system-only) and any open parking exception is resolved. Admin authority checked before the elevation.',
            function () use ($enrolmentId, $admin): void {
                $enrolment = DB::table('enrolments')->where('id', $enrolmentId)->first() ?? abort(404);
                if ($enrolment->status !== 'in_pool') {
                    abort(422, "Enrolment is {$enrolment->status}, not an unplaced (in_pool) student");
                }
                // a paid enrolment cannot be silently released — that path refunds (backstop/dissolution, OD-38/48)
                if (DB::table('orders')->where('enrolment_id', $enrolment->id)->where('status', 'paid')->exists()) {
                    abort(422, 'Enrolment has a paid order; release must go through the refund path (backstop/dissolution)');
                }
                $this->enrolments->transition($enrolment->id, 'released', $admin, 'released at deadline (OD-35)');
                $this->exceptions->resolveOpenFor((int) $enrolment->programme_id, 'enrolment', $enrolment->id, 'released', by: $admin);
            },
        );
    }

    private function assertAcademyOperator(User $admin): void
    {
        if ($admin->role !== 'academy_admin') {
            abort(403, 'Deadline matching is an academy action (OD-35)');
        }
        $caps = DB::table('admin_capabilities')->where('user_id', $admin->id)->pluck('capability');
        if (! $caps->contains('operations') && ! $caps->contains('super_admin')) {
            abort(403, 'Deadline matching requires the operations capability (OD-35)');
        }
    }

    private function assertLobbyEligible(object $team, int $studentId): void
    {
        $schoolId = DB::table('team_categories')->where('id', $team->category_id)->value('school_id');
        if ($schoolId === null) {
            return; // unbound lobby: open to any enrolled student
        }
        $linked = DB::table('school_links')->where('student_id', $studentId)->where('school_id', $schoolId)->where('status', 'active')->exists();
        if (! $linked) {
            throw ValidationException::withMessages(['lobby' => ['Student is not eligible for this team\'s lobby (school binding)']]);
        }
    }

    /** @return array<string, mixed> */
    private function teamRules(int $programmeId): array
    {
        return (array) (json_decode((string) DB::table('wizard_sections')
            ->where('programme_id', $programmeId)->where('section_key', 'team_rules')
            ->value('data'), true) ?? []);
    }
}
