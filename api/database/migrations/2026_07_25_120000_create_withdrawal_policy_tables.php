<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S02B STEP 3 — withdrawal policy (2.1/E7), SCOPED per the plan with the read
// set stated pre-build (Leo): PUBLISHED terms readable by the parties who can
// be bound by them (guardian, student, school_admin — E6 payer parties);
// draft terms academy-staff/system only; Members nothing. Bands: schema-level
// pct bounds at the DB; ordering/window rules in the service (tested).
return new class extends Migration
{
    public function up(): void
    {
        // Basics dates (D2§1) — OD-2 seeds key off the programme start
        Schema::table('programmes', function (Blueprint $table) {
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
        });

        Schema::create('withdrawal_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->unique()->constrained();
            $table->timestampTz('full_refund_before')->nullable();
            $table->timestampTz('no_refund_after')->nullable();
            $table->boolean('requires_approval')->default(true); // OD-2 default
            $table->boolean('seeded_provisional')->default(false); // OD-2: seeds await client confirmation
            $table->timestampsTz();
        });

        Schema::create('withdrawal_bands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->unsignedSmallInteger('position');
            $table->timestampTz('until_date');
            $table->smallInteger('refund_pct');
            $table->timestampsTz();
            $table->unique(['programme_id', 'position']);
            $table->unique(['programme_id', 'until_date']); // equal dates = overlap, refused at the DB
        });
        DB::statement('ALTER TABLE withdrawal_bands ADD CONSTRAINT withdrawal_bands_pct_bounds CHECK (refund_pct >= 0 AND refund_pct <= 100)');

        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $staff = "({$role} = 'academy_admin' AND ('configuration' = ANY({$caps}) OR 'finance' = ANY({$caps}) OR 'operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $config = "({$role} = 'academy_admin' AND ('configuration' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        // The bound parties read PUBLISHED terms (E6 payer parties)
        $boundParty = "({$ctx} = 'request' AND {$role} IN ('guardian','student','school_admin')
            AND programme_id IN (SELECT p.id FROM programmes p WHERE p.status = 'published'))";

        foreach (['withdrawal_policies', 'withdrawal_bands'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$table}_read ON {$table} FOR SELECT USING ({$system} OR {$staff} OR {$boundParty})");
            DB::unprepared("CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK ({$system} OR {$config})");
            DB::unprepared("CREATE POLICY {$table}_update ON {$table} FOR UPDATE USING ({$system} OR {$config}) WITH CHECK ({$system} OR {$config})");
            DB::unprepared("CREATE POLICY {$table}_delete ON {$table} FOR DELETE USING ({$system} OR {$config})");
        }
    }

    public function down(): void
    {
        foreach (['withdrawal_policies', 'withdrawal_bands'] as $table) {
            foreach (['read', 'insert', 'update', 'delete'] as $kind) {
                DB::unprepared("DROP POLICY IF EXISTS {$table}_{$kind} ON {$table}");
            }
        }
        Schema::dropIfExists('withdrawal_bands');
        Schema::dropIfExists('withdrawal_policies');
        Schema::table('programmes', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
