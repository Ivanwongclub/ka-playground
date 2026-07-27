<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04B STEP 3 — the forwardable payment link (OD-44) + provider payments
// (OD-46/47). The link row NEVER stores token plaintext (hash only); the
// payload is FROZEN at mint (initials, never a name); anonymous resolution is
// a single server-side path (single_reader assertion). Payments: one table,
// two origins — provider rows self-confirm (OD-47, out of BI-9); the manual
// origin + BI-9 recorder≠confirmer arrives in step 4.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignId('student_id')->constrained('users'); // RLS key only — never rendered
            $table->foreignId('minted_by')->constrained('users');
            $table->char('token_hash', 64)->unique(); // sha256; plaintext exists only in the mint response
            $table->string('status')->default('active'); // active|paying|paid|expired
            $table->string('order_reference');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->string('programme_name_en');
            $table->string('programme_name_tc');
            $table->string('programme_name_sc');
            $table->string('student_initials', 16); // FROZEN at mint (OD-44: initials only)
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT pl_status_check CHECK (status IN ('active','paying','paid','expired'))");
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT pl_currency_check CHECK (currency = 'HKD')");

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('origin'); // provider | manual (manual + BI-9 arrive step 4)
            $table->string('provider')->nullable(); // mock | qfpay (S-QFPAY)
            $table->string('provider_ref')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->boolean('via_link')->default(false);
            $table->string('status'); // provider rows are born confirmed (OD-47)
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT pay_origin_check CHECK (origin IN ('provider','manual'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT pay_status_check CHECK (status IN ('confirmed','pending_confirmation'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT pay_currency_check CHECK (currency = 'HKD')");

        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $finAudit = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $familyRead = "{$system} OR {$finAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})";

        DB::unprepared('ALTER TABLE payment_links ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE payment_links FORCE ROW LEVEL SECURITY');
        // Ruling 6: co-guardians SEE existence and state via their branch; the
        // hash column is visible but is not plaintext — the forwardable URL is
        // unreconstructable from any DB read. Re-sharing = re-minting.
        DB::unprepared("CREATE POLICY pl_read ON payment_links FOR SELECT USING ({$familyRead})");
        DB::unprepared("CREATE POLICY pl_insert ON payment_links FOR INSERT WITH CHECK ({$system} OR ({$role} = 'guardian' AND minted_by::text = {$actor} AND student_id::text = ANY({$students})))");
        DB::unprepared("CREATE POLICY pl_update ON payment_links FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY pl_delete ON payment_links FOR DELETE USING ({$system})");

        DB::unprepared('ALTER TABLE payments ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE payments FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY pay_read ON payments FOR SELECT USING ({$system} OR {$finAudit} OR EXISTS (SELECT 1 FROM orders o WHERE o.id = order_id))");
        DB::unprepared("CREATE POLICY pay_insert ON payments FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY pay_update ON payments FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_links');
    }
};
