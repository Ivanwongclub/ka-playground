<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S05-5 — the Activity Tracker's gate records over the FIVE FIXED stages. The
 * stages are a platform constant (GR005 / A2 matrix: five stages are fixed, not
 * programme-shaped) — there is deliberately no per-programme stage config here.
 *
 * Gate approval authority (OD-61): a TEAM-LINKED teacher approves their team's
 * gate; where none is linked, the lobby's school admin does (academy ops is the
 * platform-wide fallback). A teacher linked to a DIFFERENT team, a guardian, a
 * student or any other actor is refused — team-linked, never student-linked.
 */
class TrackerService
{
    /** The five fixed Activity Tracker stages (FR010). Order is the sequence. */
    public const STAGES = ['Plan', 'Design', 'Learn', 'Pitch', 'Launch'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
        private readonly LearnGateService $learn,
    ) {}

    public function approveGate(string $teamId, string $stage, User $approver, ?string $notes = null): array
    {
        if (! in_array($stage, self::STAGES, true)) {
            abort(422, 'Unknown stage — the five stages are fixed: '.implode(', ', self::STAGES));
        }

        return $this->scope->asSystem(
            'Stage-gate approval (S05-5, OD-61): a team-linked teacher, the lobby school admin, or academy ops records a gate pass. The approver\'s authority (team-linked, not student-linked) is resolved WITHIN the elevation using explicit actor-id filters — a gate approver may not be able to read the team through RLS, but the OD-61 decision is a policy call, not a visibility one. stage_gates is a system-only write.',
            function () use ($teamId, $stage, $approver, $notes): array {
                $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
                $kind = $this->gateApproverKind($approver, $team); // 403s if unauthorised
                // FR012 (R3): the tracker is LOCKED until the programme is Active (has started).
                // Authority is decided first (403), then the lock (422) — an authorised approver
                // still cannot pass a gate before the programme begins.
                $startsOn = json_decode((string) DB::table('wizard_sections')
                    ->where('programme_id', $team->programme_id)->where('section_key', 'basics')
                    ->value('data'), true)['starts_on'] ?? null;
                if ($startsOn === null || $startsOn > now()->format('Y-m-d')) {
                    abort(422, 'Tracker is locked until the programme begins (FR012, R3)');
                }
                // OD-12 / R2 (Option B): the Learn gate carries a computed HARD PRECONDITION —
                // the team must be Learn-eligible (enough members qualify on attendance). The
                // threshold gates the teacher's approval; it does not replace it. 0/0 (no
                // attendance yet) → not-yet-assessable → refused (never silently passed).
                if ($stage === 'Learn') {
                    $e = $this->learn->eligibility($team);
                    if (! $e['assessable']) {
                        abort(422, 'Learn gate is not yet assessable — no attendance recorded for this team (OD-12)');
                    }
                    if (! $e['eligible']) {
                        abort(422, "Team is not Learn-eligible: {$e['qualifying']}/{$e['active_members']} members qualify, needs {$e['team_gate_pass_pct']}% (OD-12)");
                    }
                }
                if (DB::table('stage_gates')->where('team_id', $team->id)->where('stage', $stage)->exists()) {
                    abort(409, "The {$stage} gate has already been passed for this team");
                }
                $id = (string) Str::uuid7();
                DB::table('stage_gates')->insert([
                    'id' => $id, 'team_id' => $team->id, 'category_id' => $team->category_id,
                    'stage' => $stage, 'status' => 'passed', 'approved_by' => $approver->id,
                    'approver_kind' => $kind, 'approved_at' => now(), 'notes' => $notes,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('stage_gate', $id, 'stage_gate.passed',
                    toState: 'passed', programmeId: (int) $team->programme_id,
                    payloadAfter: ['stage' => $stage, 'approver_kind' => $kind, 'approved_by' => $approver->id], actor: $approver);

                return ['gate_id' => $id, 'stage' => $stage, 'approver_kind' => $kind];
            },
        );
    }

    /** OD-61 authority resolution; aborts 403 when the actor may not approve this team's gate. */
    private function gateApproverKind(User $approver, object $team): string
    {
        // academy operations / super — platform-wide fallback
        if ($approver->role === 'academy_admin') {
            $caps = DB::table('admin_capabilities')->where('user_id', $approver->id)->pluck('capability');
            if ($caps->contains('operations') || $caps->contains('super_admin')) {
                return 'academy';
            }
        }
        // TEAM-linked teacher (OD-61) — linked to THIS team, not merely to a student
        if ($approver->role === 'teacher') {
            $linked = DB::table('team_teacher_links')->where('team_id', $team->id)
                ->where('teacher_id', $approver->id)->where('status', 'active')->exists();
            if ($linked) {
                return 'teacher';
            }
        }
        // lobby's school admin
        if ($approver->role === 'school_admin') {
            $schoolId = DB::table('team_categories')->where('id', $team->category_id)->value('school_id');
            if ($schoolId !== null && DB::table('school_admin_links')
                ->where('school_admin_id', $approver->id)->where('school_id', $schoolId)->where('status', 'active')->exists()) {
                return 'school_admin';
            }
        }
        abort(403, 'Not authorised to approve this team\'s gate — a team-linked teacher or the lobby school admin only (OD-61)');
    }
}
