<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04D STEP 3 — OD-24 "never silent" + the deferred write-policy hardening.
//
//  - link_visibility_events: when a guardian is ADDED to a student who already
//    has guardians, EVERY existing guardian gets a record (OD-24 — including
//    vouched additions, OD-30). S09 delivers; the RECORD must exist now so
//    "never silent" is assertable before channels exist.
//  - The hardening deferred from STEP 1 (it broke confirm + schoolVouch, both
//    non-system active-writers). Now safe: confirm pends (STEP 2) and schoolVouch
//    moves its active write into its elevation (this step). Only the system
//    context may write status='active' on the three link tables.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_visibility_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('student_id')->constrained('users');           // whose guardian set changed
            $table->foreignId('new_guardian_id')->constrained('users');      // the guardian added
            $table->uuid('new_link_id');                                     // the guardian_link that activated
            $table->foreignId('addressed_guardian_id')->constrained('users'); // the existing guardian who must see it
            $table->string('origin');                                        // vouch/school_mediated | onboarding | form_claimed …
            $table->timestampsTz();
            $table->index(['addressed_guardian_id', 'created_at']);
            $table->index('new_link_id');
        });

        // RLS: read = system · the addressed guardian · ops/audit. Write = system.
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        DB::unprepared('ALTER TABLE link_visibility_events ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE link_visibility_events FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY link_visibility_events_read ON link_visibility_events FOR SELECT USING (
            {$system} OR addressed_guardian_id::text = {$actor} OR {$ops} OR {$auditRead}
        )");
        DB::unprepared("CREATE POLICY link_visibility_events_insert ON link_visibility_events FOR INSERT WITH CHECK ({$system})");

        // ── deferred write-policy hardening: only system writes status='active' ──
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $nonSystem = [
            'guardian_links' => "guardian_id::text = {$actor} OR student_id::text = {$actor} OR {$ops}
                OR ({$role} = 'school_admin' AND student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))",
            'school_links' => "student_id::text = {$actor} OR {$ops}
                OR ({$role} IN ('school_admin','teacher') AND school_id::text = ANY({$schools}))",
            'teacher_links' => "teacher_id::text = {$actor} OR {$ops}
                OR ({$role} = 'school_admin' AND school_id::text = ANY({$schools}))",
        ];
        foreach ($nonSystem as $table => $arm) {
            // system writes anything; a non-system actor writes only NON-active rows
            $writeCheck = "{$system} OR (({$arm}) AND status <> 'active')";
            DB::unprepared("DROP POLICY {$table}_insert ON {$table}");
            DB::unprepared("CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK ({$writeCheck})");
            DB::unprepared("DROP POLICY {$table}_update ON {$table}");
            DB::unprepared("CREATE POLICY {$table}_update ON {$table} FOR UPDATE USING ({$system} OR ({$arm})) WITH CHECK ({$writeCheck})");
        }
    }

    public function down(): void
    {
        // restore the original (unhardened) INSERT/UPDATE policies
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $orig = [
            'guardian_links' => "{$system} OR guardian_id::text = {$actor} OR student_id::text = {$actor} OR {$ops}
                OR ({$role} = 'school_admin' AND student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))",
            'school_links' => "{$system} OR student_id::text = {$actor} OR {$ops}
                OR ({$role} IN ('school_admin','teacher') AND school_id::text = ANY({$schools}))",
            'teacher_links' => "{$system} OR teacher_id::text = {$actor} OR {$ops}
                OR ({$role} = 'school_admin' AND school_id::text = ANY({$schools}))",
        ];
        foreach ($orig as $table => $expr) {
            DB::unprepared("DROP POLICY {$table}_insert ON {$table}");
            DB::unprepared("CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK ({$expr})");
            DB::unprepared("DROP POLICY {$table}_update ON {$table}");
            DB::unprepared("CREATE POLICY {$table}_update ON {$table} FOR UPDATE USING ({$expr}) WITH CHECK ({$expr})");
        }
        foreach (['read', 'insert'] as $kind) {
            DB::unprepared("DROP POLICY IF EXISTS link_visibility_events_{$kind} ON link_visibility_events");
        }
        Schema::dropIfExists('link_visibility_events');
    }
};
