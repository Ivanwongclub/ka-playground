<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04B STEP 2 — the Q1 OUTBOX: the payment OBLIGATION is written atomically
// with the seat claim (S05's 成團 transaction; a fixture claim until then);
// ISSUANCE happens after commit via the system consumer. Nothing user-reachable
// writes here — INSERT is system-only, structurally.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->foreignId('programme_id')->constrained();
            $table->foreignId('student_id')->constrained('users');
            $table->string('payer_party'); // OD-25/52: guardian|student = family task; school = invoice route
            $table->foreignId('payer_school_id')->nullable()->constrained('schools');
            $table->timestampTz('consumed_at')->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained('orders');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE payment_obligations ADD CONSTRAINT po_payer_check CHECK (payer_party IN ('guardian','student','school'))");
        DB::statement("CREATE UNIQUE INDEX po_one_unconsumed ON payment_obligations (enrolment_id) WHERE consumed_at IS NULL");

        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $finAudit = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        DB::unprepared('ALTER TABLE payment_obligations ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE payment_obligations FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY po_read ON payment_obligations FOR SELECT USING ({$system} OR {$finAudit})");
        DB::unprepared("CREATE POLICY po_insert ON payment_obligations FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY po_update ON payment_obligations FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_obligations');
    }
};
