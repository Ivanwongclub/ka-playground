<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04B STEP 5 — refunds + credit notes + school-settled precedence (2.17,
// OD-25/48/54). FULL-ONLY (OD-48); destination = original payer paid by the
// academy (OD-25); BI-9 in depth on the refund payout (app + DB policy);
// credit notes & refunds INSERT-only where they are evidence (BI-5). Built
// against FIXTURE consolidated_invoices — live issuance is S05/S04E.
return new class extends Migration
{
    public function up(): void
    {
        // FIXTURE consolidated invoice (school-settled receivable, OD-53). Live
        // issuance arrives with Team Formation volume (S05/S04E); the balance invariant
        // and the OD-54 branch are proven here against seeded rows.
        Schema::create('consolidated_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('programme_id')->constrained();
            $table->bigInteger('original_amount_minor');
            $table->bigInteger('balance_minor'); // original − Σ credit notes (OD-54; asserted)
            $table->char('currency', 3)->default('HKD');
            $table->string('status')->default('issued'); // issued | paid (drives the OD-54 branch)
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE consolidated_invoices ADD CONSTRAINT ci_status_check CHECK (status IN ('issued','paid'))");
        DB::statement("ALTER TABLE consolidated_invoices ADD CONSTRAINT ci_currency_check CHECK (currency = 'HKD')");

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('consolidated_invoice_id')->nullable()->constrained('consolidated_invoices');
        });

        // Credit notes — INSERT-only (BI-5): a correction is a new record. Every
        // one traces to an approved withdrawal.
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('consolidated_invoice_id')->nullable()->constrained('consolidated_invoices');
            $table->foreignUuid('withdrawal_request_id')->constrained('withdrawal_requests');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE credit_notes ADD CONSTRAINT cn_currency_check CHECK (currency = 'HKD')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION credit_notes_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'credit_notes is INSERT-only (BI-5): % blocked', TG_OP; END $$;
            CREATE TRIGGER credit_notes_immutable_guard BEFORE UPDATE OR DELETE OR TRUNCATE ON credit_notes
                FOR EACH STATEMENT EXECUTE FUNCTION credit_notes_immutable();
            REVOKE UPDATE, DELETE, TRUNCATE ON credit_notes FROM PUBLIC;
            SQL);
        DB::unprepared("DO \$\$ BEGIN IF current_user <> 'kap_app' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname='kap_app') THEN REVOKE UPDATE, DELETE, TRUNCATE ON credit_notes FROM kap_app; END IF; END \$\$;");

        // Refunds — the 2.17 machine: requested → approved → confirmed | rejected.
        // BI-9: approver ≠ confirmer, enforced app + DB. Destination = original
        // payer (OD-25). FULL amount only (OD-48).
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('withdrawal_request_id')->constrained('withdrawal_requests');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->string('destination_party'); // guardian|student|school (= original payer, OD-25)
            $table->foreignId('destination_school_id')->nullable()->constrained('schools');
            $table->string('status')->default('requested');
            $table->text('evidence_note')->nullable(); // 2.17: payout requires evidence
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT rf_status_check CHECK (status IN ('requested','approved','confirmed','rejected'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT rf_dest_check CHECK (destination_party IN ('guardian','student','school'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT rf_dest_school_check CHECK ((destination_party = 'school') = (destination_school_id IS NOT NULL))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT rf_currency_check CHECK (currency = 'HKD')");
        DB::statement("CREATE UNIQUE INDEX refunds_one_live_per_order ON refunds (order_id) WHERE status <> 'rejected'");

        // ── RLS ──
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $finCap = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $finAudit = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        // OD-67 ruling 3: a school admin sees their OWN consolidated invoices
        $schoolOfInvoice = "({$role} = 'school_admin' AND school_id::text = ANY({$schools}))";

        DB::unprepared('ALTER TABLE consolidated_invoices ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE consolidated_invoices FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY ci_read ON consolidated_invoices FOR SELECT USING ({$system} OR {$finAudit} OR {$schoolOfInvoice})");
        DB::unprepared("CREATE POLICY ci_insert ON consolidated_invoices FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY ci_update ON consolidated_invoices FOR UPDATE USING ({$system}) WITH CHECK ({$system})");

        // credit notes: family via the parent order · finance/audit · school of invoice · system
        $cnRead = "{$system} OR {$finAudit} OR EXISTS (SELECT 1 FROM orders o WHERE o.id = order_id)
            OR EXISTS (SELECT 1 FROM consolidated_invoices ci WHERE ci.id = consolidated_invoice_id AND {$role} = 'school_admin' AND ci.school_id::text = ANY({$schools}))";
        DB::unprepared('ALTER TABLE credit_notes ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE credit_notes FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY cn_read ON credit_notes FOR SELECT USING ({$cnRead})");
        DB::unprepared("CREATE POLICY cn_insert ON credit_notes FOR INSERT WITH CHECK ({$system})"); // settlement is system-driven

        // refunds: family via order · finance/audit · destination school · system
        $rfRead = "{$system} OR {$finAudit} OR EXISTS (SELECT 1 FROM orders o WHERE o.id = order_id)
            OR ({$role} = 'school_admin' AND destination_school_id::text = ANY({$schools}))";
        DB::unprepared('ALTER TABLE refunds ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE refunds FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY rf_read ON refunds FOR SELECT USING ({$rfRead})");
        // creation: settlement (system) — the requested row is minted by the settlement service
        DB::unprepared("CREATE POLICY rf_insert ON refunds FOR INSERT WITH CHECK ({$system})");
        // BI-9 IN DEPTH: approve moves requested→approved (finance, the approver);
        // confirm moves approved→confirmed and REQUIRES confirmed_by = actor ≠ approved_by
        DB::unprepared("CREATE POLICY rf_update ON refunds FOR UPDATE
            USING ({$system} OR ({$finCap} AND status IN ('requested','approved')))
            WITH CHECK ({$system} OR (
                {$finCap} AND (
                    (status = 'approved' AND approved_by::text = {$actor})
                    OR (status = 'confirmed' AND confirmed_by::text = {$actor} AND approved_by::text <> {$actor})
                    OR (status = 'rejected')
                )))");
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('consolidated_invoice_id'));
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('consolidated_invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS credit_notes_immutable() CASCADE');
    }
};
