<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S06 STEP 4b — assessment lifecycle (2.5): Draft → Published → Open → Closed →
// Graded → Released. "Hidden until Released" is a VISIBILITY GATE enforced at
// READ, not just a workflow state: a family sees a student's result ONLY once the
// parent assessment is Released; grader/academy see all states. The embargo lives
// in the assessment_results RLS read policy — a Graded-but-not-Released grade
// returns NOTHING to the family.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->uuid('team_id')->nullable();
            $table->string('title');
            $table->string('status')->default('draft'); // draft|published|open|closed|graded|released|cancelled
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->index(['programme_id', 'status']);
        });
        DB::statement("ALTER TABLE assessments ADD CONSTRAINT assessments_status_check CHECK (status IN ('draft','published','open','closed','graded','released','cancelled'))");

        Schema::create('assessment_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('assessment_id');
            $table->uuid('enrolment_id');
            $table->foreignId('student_id')->constrained('users');
            $table->integer('score')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users');
            $table->timestampTz('graded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['assessment_id', 'enrolment_id']);
        });

        // ---- RLS ----
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        // roster: a programme's student sees its assessments (title/status); their guardian too.
        // Seeing an assessment EXISTS is fine — the RESULT is what the embargo protects.
        $studentRoster = "({$role} = 'student' AND EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = assessments.programme_id AND e.student_id::text = {$actor}))";
        $guardianRoster = "({$role} = 'guardian' AND EXISTS (SELECT 1 FROM enrolments e JOIN guardian_links gl ON gl.student_id = e.student_id
            WHERE e.programme_id = assessments.programme_id AND gl.guardian_id::text = {$actor} AND gl.status = 'active'))";

        DB::unprepared('ALTER TABLE assessments ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE assessments FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY assessments_read ON assessments FOR SELECT USING ({$system} OR {$opsAudit} OR {$studentRoster} OR {$guardianRoster})");
        DB::unprepared("CREATE POLICY assessments_insert ON assessments FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY assessments_update ON assessments FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY assessments_delete ON assessments FOR DELETE USING ({$system})");

        DB::unprepared('ALTER TABLE assessment_results ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE assessment_results FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY assessment_results_insert ON assessment_results FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY assessment_results_update ON assessment_results FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY assessment_results_delete ON assessment_results FOR DELETE USING ({$system})");
        // THE EMBARGO: family sees a result ONLY when its parent assessment is Released; academy/grader see all states.
        $released = "EXISTS (SELECT 1 FROM assessments a WHERE a.id = assessment_results.assessment_id AND a.status = 'released')";
        $ownReleased = "(student_id::text = {$actor} AND {$released})";
        $guardianReleased = "(EXISTS (SELECT 1 FROM guardian_links gl WHERE gl.student_id = assessment_results.student_id AND gl.guardian_id::text = {$actor} AND gl.status = 'active') AND {$released})";
        DB::unprepared("CREATE POLICY assessment_results_read ON assessment_results FOR SELECT USING ({$system} OR {$opsAudit} OR {$ownReleased} OR {$guardianReleased})");
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
    }
};
