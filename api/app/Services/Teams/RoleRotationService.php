<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S05-5 (OD-15) — role tenure ledger + manual rotation recording. The ledger is
 * OUR system of record (S08 mints badges from completed tenures); the rotation
 * CADENCE is external (Logto, S11), so Phase 1 staff RECORD rotations.
 *
 * A role ROTATES, never stacks: assigning a team role to a member ends the prior
 * holder's active tenure (→ completed, the handover) and opens a fresh active
 * one. The `tenures_one_active_role` partial-unique index makes "two live holders
 * of one role in a team" impossible at the database.
 */
class RoleRotationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    /** Record an assignment/rotation of a team role to a member. Returns the new tenure id. */
    public function assignRole(string $teamId, string $enrolmentId, string $roleId, User $actor): array
    {
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        $this->assertCanRecord($actor, $team);
        $role = DB::table('role_library')->where('id', $roleId)->first() ?? abort(404, 'Unknown role');
        if ((int) $role->programme_id !== (int) $team->programme_id) {
            abort(422, 'Role does not belong to this team\'s programme');
        }
        $member = DB::table('team_members')->where('team_id', $team->id)->where('enrolment_id', $enrolmentId)->where('status', 'active')->first();
        if ($member === null) {
            abort(422, 'The holder must be an active member of the team');
        }

        return $this->scope->asSystem(
            'Role rotation recording (S05-5, OD-15): staff record a role assignment; the prior active tenure for this (team, role) is completed and a fresh active one opened — the ledger handover. tenures is a system-only write; the recorder\'s authority was established before the elevation.',
            function () use ($team, $role, $member, $enrolmentId, $actor): array {
                return DB::transaction(function () use ($team, $role, $member, $enrolmentId, $actor): array {
                    // end the prior active holder of this role (the handover) — at most one, by the partial unique
                    $prior = DB::table('tenures')->where('team_id', $team->id)->where('role_id', $role->id)->where('state', 'active')->first();
                    $replaced = null;
                    if ($prior !== null) {
                        if ((int) $prior->student_id === (int) $member->student_id) {
                            abort(409, 'That member already holds this role');
                        }
                        DB::table('tenures')->where('id', $prior->id)->update([
                            'state' => 'completed', 'ended_at' => now(), 'ended_reason' => 'rotated', 'updated_at' => now(),
                        ]);
                        $this->audit->record('tenure', $prior->id, 'tenure.completed',
                            fromState: 'active', toState: 'completed', reason: 'rotated out',
                            programmeId: (int) $team->programme_id,
                            payloadAfter: ['role_id' => $role->id, 'student_id' => $prior->student_id], actor: $actor);
                        $replaced = (int) $prior->student_id;
                    }
                    $id = (string) Str::uuid7();
                    DB::table('tenures')->insert([
                        'id' => $id, 'team_id' => $team->id, 'role_id' => $role->id, 'category_id' => $team->category_id,
                        'enrolment_id' => $enrolmentId, 'student_id' => $member->student_id, 'state' => 'active',
                        'started_at' => now(), 'assigned_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->audit->record('tenure', $id, 'tenure.assigned',
                        toState: 'active', programmeId: (int) $team->programme_id,
                        payloadAfter: ['role_id' => $role->id, 'student_id' => $member->student_id, 'replaced_student_id' => $replaced], actor: $actor);

                    return ['tenure_id' => $id, 'rotated_from' => $replaced];
                });
            },
        );
    }

    private function assertCanRecord(User $actor, object $team): void
    {
        if ($actor->role === 'academy_admin') {
            $caps = DB::table('admin_capabilities')->where('user_id', $actor->id)->pluck('capability');
            if ($caps->contains('operations') || $caps->contains('super_admin')) {
                return;
            }
        }
        if ($actor->role === 'school_admin') {
            $schoolId = DB::table('team_categories')->where('id', $team->category_id)->value('school_id');
            if ($schoolId !== null && DB::table('school_admin_links')
                ->where('school_admin_id', $actor->id)->where('school_id', $schoolId)->where('status', 'active')->exists()) {
                return;
            }
        }
        abort(403, 'Only academy operations or the lobby school admin may record a role rotation (OD-15)');
    }
}
