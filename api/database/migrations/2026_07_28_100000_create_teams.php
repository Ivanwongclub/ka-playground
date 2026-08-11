<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S05 STEP 1 — formation in lobbies (TEAM-CATEGORIES §4–§8). A team belongs to
// ONE lobby (team_category) for life; joining transitions the member's
// enrolment in_pool → teamed (Team Formation → confirmed is STEP 2). Suspension lives on
// team_members (OD-45, ruling 4); capacity/claim is STEP 2.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->foreignUuid('category_id')->constrained('team_categories'); // the lobby, for life
            $table->string('name');
            $table->foreignId('created_by')->constrained('users'); // the submitter (transient, OD-42)
            $table->string('status')->default('forming'); // forming | submitted | confirmed | disbanded
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE teams ADD CONSTRAINT team_status_check CHECK (status IN ('forming','submitted','confirmed','disbanded'))");

        Schema::create('team_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams');
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->foreignUuid('category_id')->constrained('team_categories'); // denormalised from the team — breaks the teams↔team_members RLS recursion
            $table->foreignId('student_id')->constrained('users');
            $table->string('status')->default('active'); // active | suspended (OD-45) | removed
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE team_members ADD CONSTRAINT tm_status_check CHECK (status IN ('active','suspended','removed'))");
        // one live team membership per enrolment (a student is in one team per programme)
        DB::statement("CREATE UNIQUE INDEX team_members_one_live ON team_members (enrolment_id) WHERE status <> 'removed'");

        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        // a team is visible to its members (students + their guardians), the
        // lobby's school admin, ops/audit, academy, system
        $memberOf = "EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = teams.id AND tm.status <> 'removed'
            AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $lobbySchoolAdmin = "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM team_categories c
            WHERE c.id = teams.category_id AND c.school_id::text = ANY({$schools})))";

        DB::unprepared('ALTER TABLE teams ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE teams FORCE ROW LEVEL SECURITY');
        $lobbyWall = "status = 'forming' AND {$role} = 'student'
            AND EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = teams.programme_id
                        AND e.student_id::text = {$actor} AND e.status IN ('in_pool','teamed'))
            AND EXISTS (SELECT 1 FROM team_categories c WHERE c.id = teams.category_id
                        AND (c.school_id IS NULL OR c.school_id::text = ANY({$schools})))";
        DB::unprepared("CREATE POLICY teams_read ON teams FOR SELECT USING ({$system} OR {$opsAudit} OR {$memberOf} OR {$lobbySchoolAdmin} OR ({$lobbyWall}))");
        // a student creates a team as themselves; system for flows
        DB::unprepared("CREATE POLICY teams_insert ON teams FOR INSERT WITH CHECK ({$system} OR ({$role} = 'student' AND created_by::text = {$actor}))");
        DB::unprepared("CREATE POLICY teams_update ON teams FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY teams_delete ON teams FOR DELETE USING ({$system})");

        // NB: references team_categories (which references only school_links), NEVER teams —
        // teams_read reads team_members, so a team_members policy that read teams would recurse.
        $tmVisible = "{$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR EXISTS (SELECT 1 FROM team_categories c WHERE c.id = team_members.category_id
                       AND {$role} = 'school_admin' AND c.school_id::text = ANY({$schools}))";
        DB::unprepared('ALTER TABLE team_members ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE team_members FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY tm_read ON team_members FOR SELECT USING ({$tmVisible})");
        // the joining student adds their own membership; system for flows
        DB::unprepared("CREATE POLICY tm_insert ON team_members FOR INSERT WITH CHECK ({$system} OR ({$role} = 'student' AND student_id::text = {$actor}))");
        DB::unprepared("CREATE POLICY tm_update ON team_members FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
