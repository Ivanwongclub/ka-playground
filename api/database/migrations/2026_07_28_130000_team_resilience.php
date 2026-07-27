<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S05 STEP 4 — team resilience (OD-37/38/40/45/62). The four-terminal-action
// exception, the non-payment lapse cascade, dissolution re-pool, and the size
// waiver. No new tables — the exception ledger (STEP 3) already carries below_min
// and lapse; this adds:
//   team_members: grace_until / grace_extended (the grace-ONCE, OD-37) + suspended_at
//   teams:        waiver_reason / waived_by / waived_at (waiver as a FIELD, OD-40)
//   team_exceptions: 'school_leave' added to the type set (OD-62)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->timestampTz('suspended_at')->nullable();      // when the lapse suspended this member
            $table->timestampTz('grace_until')->nullable();       // grace-extended payment deadline (overrides payment_due_at + grace)
            $table->boolean('grace_extended')->default(false);    // OD-37: grace is extendable exactly ONCE
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->text('waiver_reason')->nullable();            // OD-40: under-strength waiver as a FIELD, with reason
            $table->foreignId('waived_by')->nullable()->constrained('users');
            $table->timestampTz('waived_at')->nullable();
        });

        // OD-62: student leaves school mid-programme → team stands, academy exception
        DB::statement('ALTER TABLE team_exceptions DROP CONSTRAINT te_type_check');
        DB::statement("ALTER TABLE team_exceptions ADD CONSTRAINT te_type_check CHECK (type IN ('deadline_noncompliant','parked_rollforward','failed_assignment','below_min','lapse','school_leave'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE team_exceptions DROP CONSTRAINT te_type_check');
        DB::statement("ALTER TABLE team_exceptions ADD CONSTRAINT te_type_check CHECK (type IN ('deadline_noncompliant','parked_rollforward','failed_assignment','below_min','lapse'))");
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waived_by');
            $table->dropColumn(['waiver_reason', 'waived_at']);
        });
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'grace_until', 'grace_extended']);
        });
    }
};
