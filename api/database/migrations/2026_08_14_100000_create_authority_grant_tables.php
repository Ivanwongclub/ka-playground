<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A-2 — the delegation grant tables. Writable ONLY by the platform (RLS insert/update = system); the
 * AuthorityGrantService validates every capability against the A-1 delegable catalogue before writing, and
 * elevates via ScopeContext::asSystem. NOTHING consumes these for authz decisions yet (A-3 read model,
 * A-4 policy arms, A-7 UI). No DELETE — school grants revoke via revoked_at; programme overrides are
 * current-state (the audit trail is the history). These tables ARE the delegation config, and A-1 marks
 * capabilities.grant + configuration.manage never-delegable, so no edge operator may ever write them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (a) school_authority_grants — one ACTIVE grant per (school, capability); active = revoked_at IS NULL
        //     (mirrors admin_capabilities). NO `granted` bool: active state IS revoked_at IS NULL (A-2 ruling 1 —
        //     redundant state is drift risk). School grants are positive-only; grant/withhold lives on overrides.
        Schema::create('school_authority_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('capability');
            $table->foreignId('granted_by')->constrained('users');
            $table->timestampTz('granted_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['school_id', 'capability']);
        });
        DB::statement('CREATE UNIQUE INDEX school_authority_grants_active_unique ON school_authority_grants (school_id, capability) WHERE revoked_at IS NULL');

        // (b) programme_authority_overrides — a per-programme grant/withhold of a delegable capability.
        //     school_id NULL = all schools on the programme. Current-state: setOverride() upserts (no revoked_at).
        Schema::create('programme_authority_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained('programmes');
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->string('capability');
            $table->enum('mode', ['grant', 'withhold']);
            $table->foreignId('set_by')->constrained('users');
            $table->timestampTz('set_at');
            $table->timestampsTz();
            $table->index(['programme_id', 'capability']);
        });
        // One current override per target. NULL school_id (all-schools) needs its own partial unique because
        // Postgres treats NULLs as distinct — a plain unique would allow duplicate all-schools rows.
        DB::statement('CREATE UNIQUE INDEX pao_all_schools_unique ON programme_authority_overrides (programme_id, capability) WHERE school_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX pao_per_school_unique ON programme_authority_overrides (programme_id, school_id, capability) WHERE school_id IS NOT NULL');

        // ── RLS. read = system OR ops OR auditRead OR routedSchool (a school reads its OWN, read-only).
        //         insert/update = system ONLY (platform writes via AuthorityGrantService::asSystem; NEVER an edge
        //         operator). No DELETE policy → FORCE RLS default-denies DELETE.
        // KNOWN SEAM (A-2 ruling 4): routedSchool matches school_id only, so a school does NOT read the
        // all-schools (school_id IS NULL) override rows yet. When A-4/A-7 needs a school to see its EFFECTIVE
        // overrides, the all-schools rows that affect it must become school-readable (read-only) — revisit then.
        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $routedSchool = "({$role} IN ('school_admin','teacher') AND school_id::text = ANY({$schools}))";

        foreach (['school_authority_grants', 'programme_authority_overrides'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_read ON {$t} FOR SELECT USING ({$system} OR {$ops} OR {$auditRead} OR {$routedSchool})");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_update ON {$t} FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        }
    }

    public function down(): void
    {
        foreach (['school_authority_grants', 'programme_authority_overrides'] as $t) {
            foreach (['read', 'insert', 'update'] as $k) {
                DB::unprepared("DROP POLICY IF EXISTS {$t}_{$k} ON {$t}");
            }
        }
        Schema::dropIfExists('programme_authority_overrides');
        Schema::dropIfExists('school_authority_grants');
    }
};
