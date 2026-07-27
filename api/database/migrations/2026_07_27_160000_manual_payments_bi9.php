<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04B STEP 4 — manual recording under BI-9 (OD-47 scope). The two-person rule
// is enforced AT THE DATABASE: the UPDATE policy's WITH CHECK refuses any
// confirmation where confirmer = recorder, regardless of what the app layer
// does. Evidence rides the existing BI-10 pipeline (context 'evidence').
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestampTz('paid_at')->nullable(); // when the money actually arrived
        });
        DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_status_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT pay_status_check CHECK (status IN ('confirmed','pending_confirmation','rejected'))");
        // BI-9 shape at the schema level: manual rows need a recorder; provider
        // rows must have NEITHER a recorder nor a confirmer (OD-47, both sides)
        DB::statement("ALTER TABLE payments ADD CONSTRAINT pay_origin_actors_check CHECK (
            (origin = 'manual' AND recorded_by IS NOT NULL) OR
            (origin = 'provider' AND recorded_by IS NULL AND confirmed_by IS NULL))");

        Schema::create('payment_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->uuid('upload_id'); // BI-10: rides the uploads table + scan pipeline
            $table->timestampTz('created_at')->useCurrent();
        });

        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $finCap = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        // Manual recording happens in the RECORDER's request context
        DB::unprepared('DROP POLICY pay_insert ON payments');
        DB::unprepared("CREATE POLICY pay_insert ON payments FOR INSERT WITH CHECK ({$system}
            OR ({$finCap} AND origin = 'manual' AND status = 'pending_confirmation' AND recorded_by::text = {$actor}))");
        // THE BI-9 TEETH: a finance admin may decide a pending manual payment,
        // but the WITH CHECK admits only confirmed_by = actor ≠ recorded_by
        DB::unprepared('DROP POLICY pay_update ON payments');
        DB::unprepared("CREATE POLICY pay_update ON payments FOR UPDATE
            USING ({$system} OR ({$finCap} AND origin = 'manual' AND status = 'pending_confirmation'))
            WITH CHECK ({$system} OR ({$finCap} AND origin = 'manual' AND status IN ('confirmed','rejected')
                AND confirmed_by::text = {$actor} AND recorded_by::text <> {$actor}))");

        DB::unprepared('ALTER TABLE payment_evidence ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE payment_evidence FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY pe_read ON payment_evidence FOR SELECT USING ({$system} OR EXISTS (SELECT 1 FROM payments p WHERE p.id = payment_id))");
        DB::unprepared("CREATE POLICY pe_insert ON payment_evidence FOR INSERT WITH CHECK ({$system}
            OR EXISTS (SELECT 1 FROM payments p WHERE p.id = payment_id AND p.recorded_by::text = {$actor} AND p.status = 'pending_confirmation'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_evidence');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['recorded_by', 'confirmed_by', 'note', 'paid_at']);
        });
    }
};
