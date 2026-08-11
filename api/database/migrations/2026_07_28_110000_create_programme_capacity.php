<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S05 STEP 2 — the seat counter S04A deliberately did NOT build. A narrow row
// the Team Formation transaction locks FOR UPDATE (OD-31/32). Sourced from the eligibility
// `capacity` wizard field, seeded at publish; claimed advances only inside Team Formation.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_capacity', function (Blueprint $table) {
            $table->foreignId('programme_id')->primary()->constrained();
            $table->integer('capacity');
            $table->integer('claimed')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE programme_capacity ADD CONSTRAINT pc_nonneg CHECK (capacity >= 0 AND claimed >= 0 AND claimed <= capacity)');

        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $finOpsAudit = "({$role} = 'academy_admin' AND ('finance' = ANY({$caps}) OR 'operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        DB::unprepared('ALTER TABLE programme_capacity ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE programme_capacity FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY pc_read ON programme_capacity FOR SELECT USING ({$system} OR {$finOpsAudit})");
        DB::unprepared("CREATE POLICY pc_insert ON programme_capacity FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY pc_update ON programme_capacity FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        // The counter's whole lifecycle is system-owned: seeded at publish, advanced
        // at Team Formation, and removed only if the programme itself is ever torn down (system).
        DB::unprepared("CREATE POLICY pc_delete ON programme_capacity FOR DELETE USING ({$system})");
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_capacity');
    }
};
