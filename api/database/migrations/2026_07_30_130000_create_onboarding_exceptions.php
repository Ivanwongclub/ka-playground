<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04C STEP 4 — the approval queue's escalation ledger (2.28 Q5, FR066-style).
// Approval latency is the product's front door; a queue item left too long is a
// governance risk, not a cosmetic one. A daily sweep raises an exception for any
// pending item past the threshold — "a school not keeping up is visible to the
// academy". queue.escalation_liveness proves the sweep keeps up. Mirrors the
// guardian_replacement_exceptions ledger; there is no generic queue service.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type');   // registration_request | guardian_link | held_link
            $table->uuid('subject_id');
            $table->integer('age_days');       // age at escalation
            $table->text('reason');
            $table->string('status')->default('open'); // open | resolved
            $table->timestampsTz();
            $table->index('status');
        });
        DB::statement("ALTER TABLE onboarding_exceptions ADD CONSTRAINT onboarding_exceptions_subject_check CHECK (subject_type IN ('registration_request','guardian_link','held_link'))");
        DB::statement("ALTER TABLE onboarding_exceptions ADD CONSTRAINT onboarding_exceptions_status_check CHECK (status IN ('open','resolved'))");
        // one OPEN exception per subject (idempotent escalation)
        DB::statement("CREATE UNIQUE INDEX onboarding_exceptions_open_unique ON onboarding_exceptions (subject_type, subject_id) WHERE status = 'open'");

        // RLS: read = system/ops/audit (academy-level visibility of latency);
        // write = system only (raised by the daily sweep).
        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        DB::unprepared('ALTER TABLE onboarding_exceptions ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE onboarding_exceptions FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY onboarding_exceptions_read ON onboarding_exceptions FOR SELECT USING ({$system} OR {$ops} OR {$auditRead})");
        DB::unprepared("CREATE POLICY onboarding_exceptions_insert ON onboarding_exceptions FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY onboarding_exceptions_update ON onboarding_exceptions FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
    }

    public function down(): void
    {
        foreach (['read', 'insert', 'update'] as $kind) {
            DB::unprepared("DROP POLICY IF EXISTS onboarding_exceptions_{$kind} ON onboarding_exceptions");
        }
        DB::unprepared('ALTER TABLE onboarding_exceptions NO FORCE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE onboarding_exceptions DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('onboarding_exceptions');
    }
};
