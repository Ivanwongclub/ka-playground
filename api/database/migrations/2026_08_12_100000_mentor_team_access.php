<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S-MENTOR-1 — MENTOR TEAM ACCESS (per-programme, config-gated). A LINKED teacher (team_teacher_links)
 * gains a READ-ONLY view of their team — but ONLY where the programme enables it.
 *
 * CONFIG STORAGE (owner-ruled): programmes.mentor_team_access (boolean). It MUST live on programmes, NOT
 * in wizard_sections: the config subquery below runs UNDER THE TEACHER'S RLS context, and wizard_sections
 * is configuration-gated (a teacher cannot read it), so a wizard_sections flag would always read false.
 * `programmes` has no RLS (a global table) → the flag is readable inside every arm, for every actor.
 *
 * THE ARM (added to four SELECT policies): (linked ∧ configOn)
 *   linked   = an active team_teacher_links row for this team + the acting teacher
 *   configOn = programmes.mentor_team_access on this team's programme
 * Programme is reached WITHOUT reading `teams` (teams_read reads team_members — a cycle would recurse):
 * teams_read uses teams.programme_id directly; tm_read / stage_gates_read use category_id → team_categories.
 *
 * Child-safety scope: this admits team/roster-NAMES/tracker only. Consent, payments, guardian identity and
 * family data are never admitted by any arm here. The S07 team-finance policies carry their OWN (unrelated,
 * OD-61) $teacher arm — NOT touched by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programmes', function (Blueprint $table) {
            $table->boolean('mentor_team_access')->default(false); // S-MENTOR-1: per-programme mentor read-view toggle (team_rules wizard section)
        });

        [$system, $opsAudit, $actor, $role, $students, $schools, $schoolAdminOf, $mentorArm] = $this->clauses();

        // ── 1. teams_read — team name/status. Programme via teams.programme_id (direct). ──
        $memberOf = "EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = teams.id AND tm.status <> 'removed'
            AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $lobbySchoolAdmin = "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM team_categories c
            WHERE c.id = teams.category_id AND c.school_id::text = ANY({$schools})))";
        $lobbyWall = "status = 'forming' AND {$role} = 'student'
            AND EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = teams.programme_id
                        AND e.student_id::text = {$actor} AND e.status IN ('in_pool','teamed'))
            AND EXISTS (SELECT 1 FROM team_categories c WHERE c.id = teams.category_id
                        AND (c.school_id IS NULL OR c.school_id::text = ANY({$schools})))";
        $teamsMentor = $mentorArm('teams.id', "EXISTS (SELECT 1 FROM programmes p WHERE p.id = teams.programme_id AND p.mentor_team_access)");
        DB::unprepared('DROP POLICY teams_read ON teams');
        DB::unprepared("CREATE POLICY teams_read ON teams FOR SELECT USING ({$system} OR {$opsAudit} OR {$memberOf} OR {$lobbySchoolAdmin} OR ({$lobbyWall}) OR ({$teamsMentor}))");

        // ── 2. tm_read (team_members) — roster rows. Programme via category_id → team_categories (no teams). ──
        $tmVisible = "{$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR EXISTS (SELECT 1 FROM team_categories c WHERE c.id = team_members.category_id
                       AND {$role} = 'school_admin' AND c.school_id::text = ANY({$schools}))";
        $tmMentor = $mentorArm('team_members.team_id', "EXISTS (SELECT 1 FROM team_categories c JOIN programmes p ON p.id = c.programme_id WHERE c.id = team_members.category_id AND p.mentor_team_access)");
        DB::unprepared('DROP POLICY tm_read ON team_members');
        DB::unprepared("CREATE POLICY tm_read ON team_members FOR SELECT USING ({$tmVisible} OR ({$tmMentor}))");

        // ── 3. stage_gates_read — the tracker. The EXISTING unconditional teacher arm is now CONFIG-GATED. ──
        $sgBase = "{$system} OR {$opsAudit} OR {$schoolAdminOf('stage_gates')}
            OR EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = stage_gates.team_id AND tm.status <> 'removed'
                       AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $sgMentor = $mentorArm('stage_gates.team_id', "EXISTS (SELECT 1 FROM team_categories c JOIN programmes p ON p.id = c.programme_id WHERE c.id = stage_gates.category_id AND p.mentor_team_access)");
        DB::unprepared('DROP POLICY stage_gates_read ON stage_gates');
        DB::unprepared("CREATE POLICY stage_gates_read ON stage_gates FOR SELECT USING ({$sgBase} OR ({$sgMentor}))");

        // ── team_teacher_links_read is DELIBERATELY UNCHANGED. Ruling 2 (a student on the team, or their
        //    guardian, sees who mentors it) is served by the ruling-4 elevation read GET /teams/{id}/teachers
        //    (member/guardian/ops authority, then asSystem name resolution). A raw member arm HERE would make
        //    team_teacher_links_read read team_members while tm_read (above) reads team_teacher_links — a
        //    policy CYCLE Postgres rejects (42P17 infinite recursion). The elevation crosses the wall instead.
    }

    public function down(): void
    {
        [$system, $opsAudit, $actor, $role, $students, $schools, $schoolAdminOf] = $this->clauses();

        // Restore the FOUR original policies verbatim, then drop the column.
        $memberOf = "EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = teams.id AND tm.status <> 'removed'
            AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $lobbySchoolAdmin = "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM team_categories c
            WHERE c.id = teams.category_id AND c.school_id::text = ANY({$schools})))";
        $lobbyWall = "status = 'forming' AND {$role} = 'student'
            AND EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = teams.programme_id
                        AND e.student_id::text = {$actor} AND e.status IN ('in_pool','teamed'))
            AND EXISTS (SELECT 1 FROM team_categories c WHERE c.id = teams.category_id
                        AND (c.school_id IS NULL OR c.school_id::text = ANY({$schools})))";
        DB::unprepared('DROP POLICY teams_read ON teams');
        DB::unprepared("CREATE POLICY teams_read ON teams FOR SELECT USING ({$system} OR {$opsAudit} OR {$memberOf} OR {$lobbySchoolAdmin} OR ({$lobbyWall}))");

        $tmVisible = "{$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR EXISTS (SELECT 1 FROM team_categories c WHERE c.id = team_members.category_id
                       AND {$role} = 'school_admin' AND c.school_id::text = ANY({$schools}))";
        DB::unprepared('DROP POLICY tm_read ON team_members');
        DB::unprepared("CREATE POLICY tm_read ON team_members FOR SELECT USING ({$tmVisible})");

        DB::unprepared('DROP POLICY stage_gates_read ON stage_gates');
        DB::unprepared("CREATE POLICY stage_gates_read ON stage_gates FOR SELECT USING (
            {$system} OR {$opsAudit} OR {$schoolAdminOf('stage_gates')}
            OR EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = stage_gates.team_id AND tm.status <> 'removed'
                       AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))
            OR EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = stage_gates.team_id AND ttl.status = 'active' AND ttl.teacher_id::text = {$actor}))");

        // team_teacher_links_read is normally UNCHANGED by up(). This restore is defensive: the first
        // (superseded) revision of this migration briefly added a recursive member arm here — restoring the
        // ORIGINAL definition guarantees a rollback lands the policy in its true pre-migration shape.
        DB::unprepared('DROP POLICY team_teacher_links_read ON team_teacher_links');
        DB::unprepared("CREATE POLICY team_teacher_links_read ON team_teacher_links FOR SELECT USING (
            {$system} OR {$opsAudit} OR teacher_id::text = {$actor} OR {$schoolAdminOf('team_teacher_links')})");

        Schema::table('programmes', function (Blueprint $table) {
            $table->dropColumn('mentor_team_access');
        });
    }

    /** The GUC clauses + the (linked ∧ configOn) arm factory — identical variable text to the source policies. */
    private function clauses(): array
    {
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $schoolAdminOf = fn (string $tbl) => "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM team_categories c WHERE c.id = {$tbl}.category_id AND c.school_id::text = ANY({$schools})))";
        // linked (active team_teacher_links for this team + the acting teacher) ∧ configOn (the programme flag).
        $mentorArm = fn (string $teamIdCol, string $configOn): string => "EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = {$teamIdCol} AND ttl.status = 'active' AND ttl.teacher_id::text = {$actor}) AND {$configOn}";

        return [$system, $opsAudit, $actor, $role, $students, $schools, $schoolAdminOf, $mentorArm];
    }
};
