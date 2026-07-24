<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S02B STEP 2 — programme config tables per the card's classification plan.
// team_categories + fee_items are SCOPED (Leo challenges 1–2): policies ship
// in this same migration. OD-18 is load-bearing here: money is an explicit ISO
// currency code + integer minor units — no float, no decimal, anywhere.
return new class extends Migration
{
    public function up(): void
    {
        // ── team_categories (SCOPED) — admin-created formation lobbies ──
        Schema::create('team_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->string('name_en');
            $table->string('name_tc');
            $table->string('name_sc');
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->string('assignment_rule'); // auto_by_school | open | admin_assigned
            $table->boolean('is_default')->default(false);
            $table->timestampTz('retired_at')->nullable(); // retiring keeps existing teams (TEAM-CATEGORIES §8)
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE team_categories ADD CONSTRAINT team_categories_rule_check CHECK (assignment_rule IN ('auto_by_school','open','admin_assigned'))");
        // OD-13b: exactly one default lobby per programme — DATABASE-level;
        // two concurrent saves cannot both win
        DB::statement('CREATE UNIQUE INDEX team_categories_one_default ON team_categories (programme_id) WHERE is_default');

        // ── fee_items (SCOPED) — OD-18 made real ──
        Schema::create('fee_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->string('name_en');
            $table->string('name_tc');
            $table->string('name_sc');
            $table->bigInteger('amount_minor'); // INTEGER MINOR UNITS — never float/decimal (OD-18)
            $table->char('currency', 3)->default('HKD'); // explicit ISO code (OD-18)
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE fee_items ADD CONSTRAINT fee_items_amount_nonneg CHECK (amount_minor >= 0)');
        // Phase 1 is HKD-only (OD-16/OD-18); widening to multi-currency is a
        // deliberate migration, never an accidental insert
        DB::statement("ALTER TABLE fee_items ADD CONSTRAINT fee_items_currency_phase1 CHECK (currency = 'HKD')");

        // ── global config tables (justifications in scope-map, same commit) ──
        Schema::create('stage_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->string('stage'); // A2: the five stages are FIXED
            $table->jsonb('requirements')->nullable(); // milestones, deliverables, gate conditions, OD-12 thresholds
            $table->string('approver_role')->nullable();
            $table->timestampsTz();
            $table->unique(['programme_id', 'stage']);
        });
        DB::statement("ALTER TABLE stage_requirements ADD CONSTRAINT stage_requirements_stage_check CHECK (stage IN ('plan','design','learn','pitch','launch'))");

        Schema::create('role_library', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->string('name_en');
            $table->string('name_tc');
            $table->string('name_sc');
            $table->unsignedSmallInteger('min_holders')->default(0);
            $table->unsignedSmallInteger('max_holders')->default(1);
            $table->boolean('mandatory')->default(false);
            $table->jsonb('in_team_permissions')->nullable();
            $table->string('rotation_cadence')->nullable(); // config only — Phase 1 records rotations manually (OD-15)
            $table->timestampsTz();
        });

        Schema::create('certification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->unique()->constrained();
            $table->unsignedSmallInteger('attendance_threshold_pct')->default(70); // OD-12 per-student
            $table->unsignedSmallInteger('team_gate_pass_pct')->default(60); // OD-12 per-team, editable post-creation
            $table->unsignedSmallInteger('assessment_threshold_pct')->nullable();
            $table->string('certificate_template')->nullable(); // academy-issued ONLY — no co-branding columns exist (OD-21)
            $table->timestampsTz();
        });

        Schema::create('badge_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->string('trigger_key'); // e.g. tenure.completed (S08 mints from tenures, OD-15)
            $table->jsonb('criteria')->nullable();
            $table->timestampsTz();
        });

        // ── RLS for the scoped pair — the policies ARE the step, not the backstop ──
        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $staff = "({$role} = 'academy_admin' AND ('configuration' = ANY({$caps}) OR 'operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $config = "({$role} = 'academy_admin' AND ('configuration' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $finance = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'configuration' = ANY({$caps}) OR 'operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        // team_categories read: five branches (S02B plan / Leo challenge 1)
        $categoriesRead = "{$system} OR {$staff}
            OR ({$role} IN ('school_admin','teacher','student') AND school_id::text = ANY({$schools}))
            OR ({$role} = 'guardian' AND school_id IN (SELECT sl.school_id FROM school_links sl WHERE sl.student_id::text = ANY({$students}) AND sl.status = 'active'))
            OR (school_id IS NULL AND {$ctx} = 'request' AND {$role} IN ('student','guardian','teacher','school_admin','academy_admin'))";
        DB::unprepared('ALTER TABLE team_categories ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE team_categories FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY team_categories_read ON team_categories FOR SELECT USING ({$categoriesRead})");
        DB::unprepared("CREATE POLICY team_categories_insert ON team_categories FOR INSERT WITH CHECK ({$system} OR {$config})");
        DB::unprepared("CREATE POLICY team_categories_update ON team_categories FOR UPDATE USING ({$system} OR {$config}) WITH CHECK ({$system} OR {$config})");
        DB::unprepared("CREATE POLICY team_categories_delete ON team_categories FOR DELETE USING ({$system})");

        // fee_items: commercial terms — academy staff only until the client
        // question resolves the S04A consumer clause (card plan)
        DB::unprepared('ALTER TABLE fee_items ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE fee_items FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY fee_items_read ON fee_items FOR SELECT USING ({$system} OR {$finance})");
        DB::unprepared("CREATE POLICY fee_items_insert ON fee_items FOR INSERT WITH CHECK ({$system} OR {$config})");
        DB::unprepared("CREATE POLICY fee_items_update ON fee_items FOR UPDATE USING ({$system} OR {$config}) WITH CHECK ({$system} OR {$config})");
        DB::unprepared("CREATE POLICY fee_items_delete ON fee_items FOR DELETE USING ({$system} OR {$config})");
    }

    public function down(): void
    {
        foreach (['team_categories', 'fee_items'] as $table) {
            foreach (['read', 'insert', 'update', 'delete'] as $kind) {
                DB::unprepared("DROP POLICY IF EXISTS {$table}_{$kind} ON {$table}");
            }
        }
        Schema::dropIfExists('badge_rules');
        Schema::dropIfExists('certification_rules');
        Schema::dropIfExists('role_library');
        Schema::dropIfExists('stage_requirements');
        Schema::dropIfExists('fee_items');
        Schema::dropIfExists('team_categories');
    }
};
