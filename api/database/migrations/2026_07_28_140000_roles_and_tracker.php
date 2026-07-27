<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S05 STEP 5 — roles & tracker.
//   team_teacher_links — OD-61: a teacher links to the TEAM (not students); a
//     linked teacher may approve that team's gates, else the lobby's school admin.
//   tenures           — OD-15: the role tenure ledger, our system of record
//     (S08 mints badges from completed tenures). Manual rotation recording in
//     Phase 1 (cadence is external, Logto S11). At most ONE active holder per
//     (team, role) — reassignment ROTATES: prior active → completed, new active.
//   stage_gates       — the Activity Tracker's gate records over the FIVE FIXED
//     stages (Plan/Design/Learn/Pitch/Launch — a code constant, never per-programme).
//
// All three denormalize category_id (the lobby) so their school-admin RLS branch
// references team_categories directly — never teams/team_members — avoiding the
// policy recursion of ADR-0001. Writes are system-only (services elevate after an
// authority check); reads are scoped.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_teacher_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('category_id');            // denormalized lobby (RLS, no recursion)
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->string('status')->default('active'); // active | removed
            $table->timestampsTz();
            $table->index(['team_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX ttl_one_active ON team_teacher_links (team_id, teacher_id) WHERE status = 'active'");

        Schema::create('tenures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('role_id');                // FK role_library (the CEO/captain definition)
            $table->uuid('category_id');            // denormalized lobby
            $table->uuid('enrolment_id');
            $table->foreignId('student_id')->constrained('users'); // the holder
            $table->string('state')->default('active'); // scheduled | active | completed | terminated (FR060)
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('ended_at')->nullable(); // set when rotated out / completed / terminated
            $table->foreignId('assigned_by')->constrained('users');
            $table->string('ended_reason')->nullable();
            $table->timestampsTz();
            $table->index(['team_id', 'role_id', 'state']);
            $table->index(['student_id', 'state']);
        });
        DB::statement("ALTER TABLE tenures ADD CONSTRAINT tenures_state_check CHECK (state IN ('scheduled','active','completed','terminated'))");
        // OD-15/36: at most ONE active holder of a role per team — rotation, never stacking
        DB::statement("CREATE UNIQUE INDEX tenures_one_active_role ON tenures (team_id, role_id) WHERE state = 'active'");

        Schema::create('stage_gates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('category_id');            // denormalized lobby
            $table->string('stage');                // one of Tracker::STAGES (fixed)
            $table->string('status')->default('passed'); // passed (S05 records passes); pending is the absence of a row
            $table->foreignId('approved_by')->constrained('users');
            $table->string('approver_kind');        // teacher | school_admin | academy
            $table->timestampTz('approved_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX stage_gates_one_per_stage ON stage_gates (team_id, stage)');

        // ---- RLS ----
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $schoolAdminOf = fn (string $tbl) => "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM team_categories c WHERE c.id = {$tbl}.category_id AND c.school_id::text = ANY({$schools})))";

        foreach (['team_teacher_links', 'tenures', 'stage_gates'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_update ON {$t} FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_delete ON {$t} FOR DELETE USING ({$system})");
        }

        // team_teacher_links: the linked teacher sees their own link; ops/audit; lobby school admin
        DB::unprepared("CREATE POLICY team_teacher_links_read ON team_teacher_links FOR SELECT USING (
            {$system} OR {$opsAudit} OR teacher_id::text = {$actor} OR {$schoolAdminOf('team_teacher_links')})");

        // tenures: the holder (or their guardian) sees it; ops/audit; lobby school admin
        DB::unprepared("CREATE POLICY tenures_read ON tenures FOR SELECT USING (
            {$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR EXISTS (SELECT 1 FROM guardian_links gl WHERE gl.student_id = tenures.student_id AND gl.guardian_id::text = {$actor} AND gl.status = 'active')
            OR {$schoolAdminOf('tenures')})");

        // stage_gates: team members (via denormalized team_members read), ops/audit, lobby school admin, linked teacher
        DB::unprepared("CREATE POLICY stage_gates_read ON stage_gates FOR SELECT USING (
            {$system} OR {$opsAudit} OR {$schoolAdminOf('stage_gates')}
            OR EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = stage_gates.team_id AND tm.status <> 'removed'
                       AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))
            OR EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = stage_gates.team_id AND ttl.status = 'active' AND ttl.teacher_id::text = {$actor}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_gates');
        Schema::dropIfExists('tenures');
        Schema::dropIfExists('team_teacher_links');
    }
};
