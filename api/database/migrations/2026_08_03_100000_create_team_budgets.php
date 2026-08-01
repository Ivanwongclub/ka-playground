<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * S07 STEP 1 (FR061 · Spec §N5/§P1) — team-PROJECT budgets, the Plan-stage
 * finance. RECORD-ONLY: the platform never holds team money (§A3). WHOLLY
 * SEPARATE from the enrolment Order module (GR006) — no order/receipt/invoice
 * reference exists on these tables, by construction.
 *
 *  - budget_categories: a FIXED SEEDED reference set (D-B1), trilingual,
 *    aggregatable — global reference data, no personal/commercial data.
 *  - team_budgets: one budget per team, state machine (§P1, stored as codes):
 *    draft → submitted → under_review → approved → active → closed
 *                                    \→ changes_requested → draft
 *  - budget_lines: planned amounts per category (OD-18 minor units, HKD).
 *    IMMUTABLE once the budget is active/closed (BI-5, DB-enforced trigger) —
 *    corrections are a new revision, never edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── budget_categories — fixed seeded reference (D-B1) ──
        Schema::create('budget_categories', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('name_en');
            $table->string('name_tc');
            $table->string('name_sc');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        $now = now();
        DB::table('budget_categories')->insert([
            ['code' => 'materials', 'name_en' => 'Materials', 'name_tc' => '物料', 'name_sc' => '物料', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'marketing', 'name_en' => 'Marketing', 'name_tc' => '市場推廣', 'name_sc' => '市场推广', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'travel', 'name_en' => 'Travel', 'name_tc' => '交通', 'name_sc' => '交通', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'other', 'name_en' => 'Other', 'name_tc' => '其他', 'name_sc' => '其他', 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // ── team_budgets (scoped) ──
        Schema::create('team_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('status')->default('draft');
            $table->char('currency', 3)->default('HKD');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->foreign('team_id')->references('id')->on('teams');
            $table->index(['team_id', 'status']);
        });
        DB::statement("ALTER TABLE team_budgets ADD CONSTRAINT tb_status_check CHECK (status IN ('draft','submitted','under_review','approved','changes_requested','active','closed'))");
        DB::statement("ALTER TABLE team_budgets ADD CONSTRAINT tb_currency_check CHECK (currency = 'HKD')");

        // ── budget_lines (scoped; team_id denormalised for RLS) ──
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('budget_id');
            $table->uuid('team_id');                              // denormalised for a direct RLS predicate
            $table->string('category')->default('other');        // FK budget_categories.code
            $table->string('name');
            $table->bigInteger('planned_amount_minor');
            $table->char('currency', 3)->default('HKD');
            $table->timestampsTz();
            $table->foreign('budget_id')->references('id')->on('team_budgets')->cascadeOnDelete();
            $table->foreign('category')->references('code')->on('budget_categories');
            $table->index('budget_id');
        });
        DB::statement("ALTER TABLE budget_lines ADD CONSTRAINT bl_amount_nonneg CHECK (planned_amount_minor >= 0)");

        // ── RLS: team-scoped read (members · linked teacher · lobby school admin · ops/audit); system write ──
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'finance' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $read = function (string $teamCol) use ($system, $opsAudit, $actor, $role, $students, $schools) {
            $memberOf = "EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = {$teamCol} AND tm.status <> 'removed' AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
            $teacher = "({$role} = 'teacher' AND EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = {$teamCol} AND ttl.teacher_id::text = {$actor} AND ttl.status = 'active'))";
            $lobbyAdmin = "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM teams t JOIN team_categories c ON c.id = t.category_id WHERE t.id = {$teamCol} AND c.school_id::text = ANY({$schools})))";

            return "{$system} OR {$opsAudit} OR {$memberOf} OR {$teacher} OR {$lobbyAdmin}";
        };
        foreach (['team_budgets' => 'team_budgets.team_id', 'budget_lines' => 'budget_lines.team_id'] as $t => $teamCol) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_read ON {$t} FOR SELECT USING ({$read($teamCol)})");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_update ON {$t} FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_delete ON {$t} FOR DELETE USING ({$system})");
        }

        // ── BI-5: budget_lines immutable once the budget is active/closed ──
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION budget_lines_immutable_when_active() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM team_budgets b WHERE b.id = OLD.budget_id AND b.status IN ('active','closed')) THEN
                    RAISE EXCEPTION 'budget_lines is immutable once the budget is active (BI-5): corrections are a new budget revision, not edits — % blocked', TG_OP;
                END IF;
                RETURN OLD;
            END $$;
            CREATE TRIGGER budget_lines_immutable_guard
                BEFORE UPDATE OR DELETE ON budget_lines
                FOR EACH ROW EXECUTE FUNCTION budget_lines_immutable_when_active();
        SQL);
        // TRUNCATE can't be row-level — block it outright (lines are never truncated)
        DB::unprepared("DO \$\$ BEGIN IF current_user <> 'kap_app' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname='kap_app') THEN REVOKE TRUNCATE ON budget_lines FROM kap_app; END IF; END \$\$;");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS budget_lines_immutable_guard ON budget_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS budget_lines_immutable_when_active() CASCADE');
        foreach (['budget_lines', 'team_budgets'] as $t) {
            foreach (['read', 'insert', 'update', 'delete'] as $k) {
                DB::unprepared("DROP POLICY IF EXISTS {$t}_{$k} ON {$t}");
            }
        }
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('team_budgets');
        Schema::dropIfExists('budget_categories');
    }
};
