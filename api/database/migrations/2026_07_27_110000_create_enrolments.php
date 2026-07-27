<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04A STEP 2 — enrolment as INTENT (team-based capacity, OD-31): no seat
// columns, no hold window, no waitlist table. The pool is a STATE (OD-34).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrolments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('acting_guardian_id')->constrained('users'); // 2.22
            $table->string('status')->default('submitted');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE enrolments ADD CONSTRAINT enr_status_check CHECK (status IN ('submitted','pending_consent','in_pool','teamed','confirmed','active','completed','withdrawn','released'))");
        // 2.8 / BI-4 / OD-63: one live enrolment per student × programme
        DB::statement("CREATE UNIQUE INDEX enrolments_one_live ON enrolments (student_id, programme_id) WHERE status NOT IN ('completed','withdrawn','released')");

        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        $read = "{$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR ({$role} = 'school_admin' AND student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))";
        // creation: a guardian, for THEIR OWN student, as themselves — or system
        $insert = "{$system} OR ({$role} = 'guardian' AND student_id::text = ANY({$students}) AND acting_guardian_id::text = {$actor})";

        DB::unprepared('ALTER TABLE enrolments ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE enrolments FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY enr_read ON enrolments FOR SELECT USING ({$read})");
        DB::unprepared("CREATE POLICY enr_insert ON enrolments FOR INSERT WITH CHECK ({$insert})");
        // the state machine writes as system, exclusively
        DB::unprepared("CREATE POLICY enr_update ON enrolments FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY enr_delete ON enrolments FOR DELETE USING ({$system})");
    }

    public function down(): void
    {
        foreach (['enr_read', 'enr_insert', 'enr_update', 'enr_delete'] as $p) {
            DB::unprepared("DROP POLICY IF EXISTS {$p} ON enrolments");
        }
        Schema::dropIfExists('enrolments');
    }
};
