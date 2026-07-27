<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04A STEP 4 — withdrawal workflow, STATE ONLY (BI-7). Money is S04B (full
// refund only, OD-48); team side-effects are S05. Approver is FIXED to academy
// operations (OD-26); pastoral endorsements are records, never authority (2.29).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('requested_by')->constrained('users'); // acting guardian, 2.22
            $table->text('reason');
            $table->string('status')->default('pending'); // pending|approved|rejected|cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE withdrawal_requests ADD CONSTRAINT wr_status_check CHECK (status IN ('pending','approved','rejected','cancelled'))");
        DB::statement("CREATE UNIQUE INDEX withdrawal_one_pending ON withdrawal_requests (enrolment_id) WHERE status = 'pending'");

        Schema::create('withdrawal_endorsements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('withdrawal_request_id')->constrained('withdrawal_requests');
            $table->foreignId('endorser_id')->constrained('users');
            $table->string('endorser_role'); // snapshot: school_admin now; teacher arrives S05
            $table->text('comment');
            $table->timestampTz('created_at')->useCurrent();
        });

        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $schoolOfStudent = "({$role} = 'school_admin' AND student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))";

        $read = "{$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students}) OR {$schoolOfStudent}";
        DB::unprepared('ALTER TABLE withdrawal_requests ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE withdrawal_requests FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY wr_read ON withdrawal_requests FOR SELECT USING ({$read})");
        DB::unprepared("CREATE POLICY wr_insert ON withdrawal_requests FOR INSERT WITH CHECK ({$system} OR ({$role} = 'guardian' AND requested_by::text = {$actor} AND student_id::text = ANY({$students})))");
        // decisions: ops; cancel: the REQUESTER alone (OD-6 conflict rule in the service)
        DB::unprepared("CREATE POLICY wr_update ON withdrawal_requests FOR UPDATE USING ({$system} OR {$ops} OR requested_by::text = {$actor}) WITH CHECK ({$system} OR {$ops} OR requested_by::text = {$actor})");
        DB::unprepared("CREATE POLICY wr_delete ON withdrawal_requests FOR DELETE USING ({$system})");

        $eRead = "{$system} OR {$opsAudit} OR EXISTS (SELECT 1 FROM withdrawal_requests wr WHERE wr.id = withdrawal_request_id AND (wr.student_id::text = {$actor} OR wr.student_id::text = ANY({$students}) OR ({$role} = 'school_admin' AND wr.student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))))";
        DB::unprepared('ALTER TABLE withdrawal_endorsements ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE withdrawal_endorsements FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY we_read ON withdrawal_endorsements FOR SELECT USING ({$eRead})");
        DB::unprepared("CREATE POLICY we_insert ON withdrawal_endorsements FOR INSERT WITH CHECK ({$system} OR (endorser_id::text = {$actor} AND EXISTS (SELECT 1 FROM withdrawal_requests wr WHERE wr.id = withdrawal_request_id AND ({$schoolOfStudent}))))");
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_endorsements');
        Schema::dropIfExists('withdrawal_requests');
    }
};
