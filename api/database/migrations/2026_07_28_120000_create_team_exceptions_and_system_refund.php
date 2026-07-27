<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S05 STEP 3 — the deadline resolution ledger (OD-35/36) and the vehicle for the
// SYSTEM auto-refund the 90-day parking backstop needs (Leo ruling 2026-07-27).
//
// team_exceptions is the S05 exception ledger (FR066 family): STEP 3 writes
// deadline_noncompliant / parked_rollforward / failed_assignment; STEP 4 extends
// it with below_min / lapse. Writes are system-only (the job/service elevates);
// reads are academy-scoped. The policy references NO other scoped table (only
// role/caps GUCs) — no RLS recursion (ADR-0001).
//
// refunds gains a system auto-refund path: withdrawal_request_id becomes nullable
// and `origin` distinguishes 'withdrawal' (BI-9 two-person, the S04B path) from
// 'backstop_auto' (SYSTEM, out of BI-9 per OD-47 — non-manual/self-confirm money
// is already outside BI-9). OD-48 full-only is unchanged: the amount is the order
// total by construction.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->string('type'); // deadline_noncompliant | parked_rollforward | failed_assignment (STEP 4: below_min | lapse)
            $table->uuid('team_id')->nullable();          // team-scoped exceptions
            $table->uuid('enrolment_id')->nullable();     // student-scoped exceptions (parked/failed)
            $table->string('status')->default('open');    // open | resolved | auto_released
            $table->text('reason')->nullable();
            $table->timestampTz('backstop_at')->nullable(); // parked_rollforward: when the auto-refund+release fires
            $table->string('resolution')->nullable();     // matched | rolled | released | auto_refund_release | auto_release
            $table->foreignId('created_by')->nullable()->constrained('users'); // null = SYSTEM
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['programme_id', 'status', 'type']);
            $table->index(['status', 'backstop_at']); // the backstop scan
        });
        DB::statement("ALTER TABLE team_exceptions ADD CONSTRAINT te_type_check CHECK (type IN ('deadline_noncompliant','parked_rollforward','failed_assignment','below_min','lapse'))");
        DB::statement("ALTER TABLE team_exceptions ADD CONSTRAINT te_status_check CHECK (status IN ('open','resolved','auto_released'))");

        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        DB::unprepared('ALTER TABLE team_exceptions ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE team_exceptions FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY te_read ON team_exceptions FOR SELECT USING ({$system} OR {$opsAudit})");
        DB::unprepared("CREATE POLICY te_insert ON team_exceptions FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY te_update ON team_exceptions FOR UPDATE USING ({$system}) WITH CHECK ({$system})");

        // refunds: allow the SYSTEM auto-refund (no withdrawal request) + tag origin
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('origin')->default('withdrawal'); // withdrawal | backstop_auto
        });
        DB::statement('ALTER TABLE refunds ALTER COLUMN withdrawal_request_id DROP NOT NULL');
        // integrity: a withdrawal refund carries its request; a backstop_auto one never does
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT rf_origin_request_check CHECK ((origin = 'withdrawal') = (withdrawal_request_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS rf_origin_request_check');
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
        // NOTE: withdrawal_request_id is left nullable on rollback (no data to restore locally).
        Schema::dropIfExists('team_exceptions');
    }
};
