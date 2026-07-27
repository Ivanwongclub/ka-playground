<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04B STEP 1 — orders, immutable lines, gapless receipts (BI-2/BI-3/BI-5,
// OD-18/25/48/67). Read sets per OD-67: every active guardian reads (and may
// pay); the student reads; school admins NEVER see family orders.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->foreignId('programme_id')->constrained();
            $table->foreignId('student_id')->constrained('users');
            $table->string('payer_party'); // OD-25: WHO PAYS — never who collects
            $table->foreignId('payer_school_id')->nullable()->constrained('schools');
            $table->string('status')->default('issued');
            $table->bigInteger('total_amount_minor'); // OD-18
            $table->char('currency', 3)->default('HKD');
            $table->timestampTz('payment_due_at')->nullable(); // OD-43 clock, set at issuance for family-paid
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ord_payer_check CHECK (payer_party IN ('guardian','student','school'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ord_status_check CHECK (status IN ('issued','paid','covered_by_invoice','refunded','cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ord_currency_check CHECK (currency = 'HKD')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ord_school_payer_check CHECK ((payer_party = 'school') = (payer_school_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX orders_one_live_per_enrolment ON orders (enrolment_id) WHERE status <> 'cancelled'");

        Schema::create('order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('name_en'); // OD-67: the PROGRAMME-level fee, snapshotted — same for all
            $table->string('name_tc');
            $table->string('name_sc');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE order_lines ADD CONSTRAINT ol_currency_check CHECK (currency = 'HKD')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_lines_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'order_lines is INSERT-only (BI-5): corrections are credit notes/refunds, never edits — % blocked', TG_OP;
            END $$;
            CREATE TRIGGER order_lines_immutable_guard
                BEFORE UPDATE OR DELETE OR TRUNCATE ON order_lines
                FOR EACH STATEMENT EXECUTE FUNCTION order_lines_immutable();
            REVOKE UPDATE, DELETE, TRUNCATE ON order_lines FROM PUBLIC;
            SQL);
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF current_user <> 'kap_app' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'kap_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON order_lines FROM kap_app;
                END IF;
            END $$;
            SQL);

        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->bigInteger('next_number');
        });
        DB::table('receipt_sequences')->insert(['key' => 'KAP', 'next_number' => 1]);

        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('sequence_key');
            $table->bigInteger('receipt_number'); // assigned INSIDE the issuing tx (BI-2)
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestampTz('issued_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['sequence_key', 'receipt_number']);
        });
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT rcp_currency_check CHECK (currency = 'HKD')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION receipts_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'receipts are immutable (BI-2): % blocked', TG_OP;
            END $$;
            CREATE TRIGGER receipts_immutable_guard
                BEFORE UPDATE OR DELETE OR TRUNCATE ON receipts
                FOR EACH STATEMENT EXECUTE FUNCTION receipts_immutable();
            REVOKE UPDATE, DELETE, TRUNCATE ON receipts FROM PUBLIC;
            SQL);
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF current_user <> 'kap_app' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'kap_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON receipts FROM kap_app;
                END IF;
            END $$;
            SQL);

        // ── RLS per OD-67 ──
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $finAudit = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        // OD-67: EVERY active guardian reads (and may pay); the student reads;
        // school admins NEVER (their money view is consolidated invoices, later)
        $familyRead = "{$system} OR {$finAudit} OR student_id::text = {$actor} OR student_id::text = ANY({$students})";

        foreach (['orders'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_read ON {$t} FOR SELECT USING ({$familyRead})");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_update ON {$t} FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_delete ON {$t} FOR DELETE USING ({$system})");
        }
        // lines/receipts: visibility derives from the PARENT order's policies
        $viaOrder = "{$system} OR EXISTS (SELECT 1 FROM orders o WHERE o.id = order_id)";
        foreach (['order_lines', 'receipts'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_read ON {$t} FOR SELECT USING ({$viaOrder})");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
        }
        DB::unprepared('ALTER TABLE receipt_sequences ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE receipt_sequences FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY rs_read ON receipt_sequences FOR SELECT USING ({$system} OR {$finAudit})");
        DB::unprepared("CREATE POLICY rs_update ON receipt_sequences FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY rs_insert ON receipt_sequences FOR INSERT WITH CHECK ({$system})");
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('receipt_sequences');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        DB::unprepared('DROP FUNCTION IF EXISTS order_lines_immutable() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS receipts_immutable() CASCADE');
    }
};
