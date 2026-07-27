<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S05-5 (OD-61) — a teacher links to the TEAM, not to individual students. The
 * link is created by the lobby's school admin or an academy admin; a linked
 * teacher may then approve that team's stage gates. Required before a team's
 * first gate, not at 成團.
 */
class TeamTeacherLinkService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScopeContext $scope,
    ) {}

    public function link(string $teamId, int $teacherId, User $admin): string
    {
        $team = DB::table('teams')->where('id', $teamId)->first() ?? abort(404);
        $this->assertCanManageTeam($admin, $team);
        $teacher = DB::table('users')->where('id', $teacherId)->first();
        if ($teacher === null || $teacher->role !== 'teacher') {
            abort(422, 'The linked account must be a teacher (OD-61)');
        }

        return $this->scope->asSystem(
            'Team-teacher link (S05-5, OD-61): the lobby school admin or an academy admin links a teacher to a TEAM (not to students). team_teacher_links is a system-only write; the admin authority was established before the elevation.',
            function () use ($team, $teacherId, $admin): string {
                if (DB::table('team_teacher_links')->where('team_id', $team->id)->where('teacher_id', $teacherId)->where('status', 'active')->exists()) {
                    abort(409, 'That teacher is already linked to this team');
                }
                $id = (string) Str::uuid7();
                DB::table('team_teacher_links')->insert([
                    'id' => $id, 'team_id' => $team->id, 'category_id' => $team->category_id,
                    'teacher_id' => $teacherId, 'created_by' => $admin->id, 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('team_teacher_link', $id, 'team_teacher_link.created',
                    programmeId: (int) $team->programme_id, reason: 'teacher linked to team (OD-61)',
                    payloadAfter: ['team_id' => $team->id, 'teacher_id' => $teacherId], actor: $admin);

                return $id;
            },
        );
    }

    private function assertCanManageTeam(User $admin, object $team): void
    {
        if ($admin->role === 'academy_admin') {
            $caps = DB::table('admin_capabilities')->where('user_id', $admin->id)->pluck('capability');
            if ($caps->contains('operations') || $caps->contains('super_admin')) {
                return;
            }
        }
        if ($admin->role === 'school_admin') {
            $schoolId = DB::table('team_categories')->where('id', $team->category_id)->value('school_id');
            if ($schoolId !== null && DB::table('school_admin_links')
                ->where('school_admin_id', $admin->id)->where('school_id', $schoolId)->where('status', 'active')->exists()) {
                return;
            }
        }
        abort(403, 'Only the lobby school admin or an academy admin may link a teacher to this team (OD-61)');
    }
}
