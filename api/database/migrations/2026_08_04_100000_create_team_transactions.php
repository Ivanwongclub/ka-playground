<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S07 STEP 2 (FR061 · Spec §P1) — team-project TRANSACTIONS + verification, the
 * SoD core. Record-only, WHOLLY SEPARATE from the Order module (§A3/GR006).
 *
 * State machine (§P1, codes):
 *   draft → receipt_attached → submitted → under_review → approved → recorded → verified
 *                                                       \→ rejected
 * Evidence is attached BEFORE submitted, so Verified-without-evidence is
 * structurally impossible (state ordering) — nothing may pass `submitted`
 * without a clean evidence upload (BI-10).
 *
 * The NEW segregation-of-duty (D-16 — the BI-9 PATTERN on a NEW table, re-homing
 * nothing): a transaction's `verified_by` ≠ its `recorded_by`. Because the
 * services write under SYSTEM elevation, the teeth are a CHECK CONSTRAINT (which
 * binds every writer, system and superuser alike — stronger than a WITH CHECK an
 * owner could bypass) + an app-service 403. Distinct from BI-9's academy-finance
 * SoD; this is team-scoped.
 *
 * Financial fields are IMMUTABLE once recorded (BI-5, DB trigger) — corrections
 * are a new (reversing) transaction, never edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('type');                                    // income | expense
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->uuid('budget_line_id')->nullable();
            $table->foreignId('beneficiary_member_id')->nullable()->constrained('users'); // D-B4: charity distribution detector
            $table->string('description');
            $table->date('occurred_on');                               // the offline date
            $table->string('status')->default('draft');
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->uuid('evidence_upload_id')->nullable();
            $table->boolean('over_budget_acknowledged')->default(false); // D-B5: informed over-budget approval
            $table->timestampTz('recorded_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->foreign('team_id')->references('id')->on('teams');
            $table->foreign('budget_line_id')->references('id')->on('budget_lines');
            $table->index(['team_id', 'status']);
        });
        DB::statement("ALTER TABLE team_transactions ADD CONSTRAINT tt_type_check CHECK (type IN ('income','expense'))");
        DB::statement('ALTER TABLE team_transactions ADD CONSTRAINT tt_amount_pos CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE team_transactions ADD CONSTRAINT tt_currency_check CHECK (currency = 'HKD')");
        DB::statement("ALTER TABLE team_transactions ADD CONSTRAINT tt_status_check CHECK (status IN ('draft','receipt_attached','submitted','under_review','approved','rejected','recorded','verified'))");
        // THE SoD TEETH (D-16): a verifier can never be the recorder.
        DB::statement('ALTER TABLE team_transactions ADD CONSTRAINT tt_sod_check CHECK (verified_by IS NULL OR verified_by <> recorded_by)');
        // Verified implies evidence (belt for the state-ordering guarantee).
        DB::statement("ALTER TABLE team_transactions ADD CONSTRAINT tt_verified_has_evidence CHECK (status <> 'verified' OR evidence_upload_id IS NOT NULL)");

        // ── RLS: team-scoped read (members · linked teacher · lobby admin · ops/audit); system write ──
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'finance' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $memberOf = "EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = team_transactions.team_id AND tm.status <> 'removed' AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $teacher = "({$role} = 'teacher' AND EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = team_transactions.team_id AND ttl.teacher_id::text = {$actor} AND ttl.status = 'active'))";
        $lobbyAdmin = "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM teams t JOIN team_categories c ON c.id = t.category_id WHERE t.id = team_transactions.team_id AND c.school_id::text = ANY({$schools})))";
        DB::unprepared('ALTER TABLE team_transactions ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE team_transactions FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY team_transactions_read ON team_transactions FOR SELECT USING ({$system} OR {$opsAudit} OR {$memberOf} OR {$teacher} OR {$lobbyAdmin})");
        DB::unprepared("CREATE POLICY team_transactions_insert ON team_transactions FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY team_transactions_update ON team_transactions FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY team_transactions_delete ON team_transactions FOR DELETE USING ({$system})");

        // ── BI-5: financial fields immutable once recorded; recorded rows never deleted ──
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION team_transactions_immutable_once_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status IN ('recorded','verified') THEN
                        RAISE EXCEPTION 'a recorded team_transaction cannot be deleted (BI-5) — corrections are a new reversing transaction';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status IN ('recorded','verified') AND (
                       NEW.amount_minor <> OLD.amount_minor
                    OR NEW.type <> OLD.type
                    OR NEW.currency <> OLD.currency
                    OR NEW.occurred_on <> OLD.occurred_on
                    OR NEW.recorded_by <> OLD.recorded_by
                    OR NEW.budget_line_id IS DISTINCT FROM OLD.budget_line_id
                    OR NEW.beneficiary_member_id IS DISTINCT FROM OLD.beneficiary_member_id
                    OR NEW.evidence_upload_id IS DISTINCT FROM OLD.evidence_upload_id
                ) THEN
                    RAISE EXCEPTION 'team_transaction financial fields are immutable once recorded (BI-5) — corrections are a new reversing transaction, not edits';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER team_transactions_immutable_guard
                BEFORE UPDATE OR DELETE ON team_transactions
                FOR EACH ROW EXECUTE FUNCTION team_transactions_immutable_once_recorded();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS team_transactions_immutable_guard ON team_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS team_transactions_immutable_once_recorded() CASCADE');
        foreach (['read', 'insert', 'update', 'delete'] as $k) {
            DB::unprepared("DROP POLICY IF EXISTS team_transactions_{$k} ON team_transactions");
        }
        Schema::dropIfExists('team_transactions');
    }
};
