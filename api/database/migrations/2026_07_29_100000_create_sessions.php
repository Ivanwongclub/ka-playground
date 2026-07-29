<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S06 STEP 2 — domain sessions with a real lifecycle (2.3), their immutable
// reschedule history (session_versions), the booking rows reschedule/clash
// operate on (session_bookings — the WORKFLOW is STEP 3), and the mentor
// lifecycle (2.6).
//
// The domain table is `programme_sessions` — 'sessions' is taken by the Laravel
// framework session store (infrastructure). Sessions bind to the SHIPPED roster
// (D4): a session belongs to a programme and optionally to one team (S05);
// attendees are that programme's enrolments / that team's members. Writes are
// system-only (services elevate).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->uuid('team_id')->nullable();          // team-specific session; null = programme-wide (D4)
            $table->string('title');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->integer('capacity');
            $table->foreignId('mentor_id')->nullable()->constrained('users'); // the assigned mentor (a teacher)
            $table->string('status')->default('draft');   // draft|published|full|in_progress|completed|cancelled|rescheduled
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->index(['programme_id', 'status']);
            $table->index(['mentor_id', 'starts_at']);
        });
        DB::statement("ALTER TABLE programme_sessions ADD CONSTRAINT ps_status_check CHECK (status IN ('draft','published','full','in_progress','completed','cancelled','rescheduled'))");
        DB::statement('ALTER TABLE programme_sessions ADD CONSTRAINT ps_time_order CHECK (ends_at > starts_at)');

        Schema::create('session_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');                   // → programme_sessions.id
            $table->unsignedInteger('version');
            $table->jsonb('payload');                     // snapshot BEFORE the change: starts_at, ends_at, capacity, status
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['session_id', 'version']);
        });

        Schema::create('session_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');                   // → programme_sessions.id
            $table->uuid('enrolment_id');
            $table->foreignId('student_id')->constrained('users');
            $table->string('status')->default('booked');  // booked|waitlisted|cancelled|attended|no_show (STEP 3 workflow)
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE session_bookings ADD CONSTRAINT sb_status_check CHECK (status IN ('booked','waitlisted','cancelled','attended','no_show'))");
        DB::statement("CREATE UNIQUE INDEX sb_one_live ON session_bookings (session_id, enrolment_id) WHERE status <> 'cancelled'");

        Schema::create('mentors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('status')->default('active');  // active|inactive|departed (2.6)
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE mentors ADD CONSTRAINT mentors_status_check CHECK (status IN ('active','inactive','departed'))");

        // ---- RLS ----
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        // visible to: system; ops/audit; its mentor; a student with an active enrolment in the programme (D4 roster)
        $sessionRoster = "EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = programme_sessions.programme_id
            AND (e.student_id::text = {$actor} OR e.student_id::text = ANY({$students})))";

        DB::unprepared('ALTER TABLE programme_sessions ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE programme_sessions FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY ps_read ON programme_sessions FOR SELECT USING ({$system} OR {$opsAudit} OR mentor_id::text = {$actor} OR ({$sessionRoster}))");
        DB::unprepared("CREATE POLICY ps_insert ON programme_sessions FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY ps_update ON programme_sessions FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY ps_delete ON programme_sessions FOR DELETE USING ({$system})");

        foreach (['session_versions', 'session_bookings', 'mentors'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_update ON {$t} FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_delete ON {$t} FOR DELETE USING ({$system})");
        }
        DB::unprepared("CREATE POLICY session_versions_read ON session_versions FOR SELECT USING ({$system} OR {$opsAudit})");
        DB::unprepared("CREATE POLICY session_bookings_read ON session_bookings FOR SELECT USING (
            {$system} OR {$opsAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})
            OR EXISTS (SELECT 1 FROM guardian_links gl WHERE gl.student_id = session_bookings.student_id AND gl.guardian_id::text = {$actor} AND gl.status = 'active')
            OR EXISTS (SELECT 1 FROM programme_sessions s WHERE s.id = session_bookings.session_id AND s.mentor_id::text = {$actor}))");
        DB::unprepared("CREATE POLICY mentors_read ON mentors FOR SELECT USING ({$system} OR {$opsAudit} OR user_id::text = {$actor})");
    }

    public function down(): void
    {
        Schema::dropIfExists('mentors');
        Schema::dropIfExists('session_bookings');
        Schema::dropIfExists('session_versions');
        Schema::dropIfExists('programme_sessions');
    }
};
