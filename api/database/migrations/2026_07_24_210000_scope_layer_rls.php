<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S02A STEP 3 — the FR006 scope layer: school_admin affiliation, RLS policies
// on every 'scoped' table (fail-closed, FORCE so owners obey), and the
// narrow-only guard on permission_overrides (Leo amendment 1).
return new class extends Migration
{
    public function up(): void
    {
        // School Admin ↔ school affiliation (scope derivation source)
        Schema::create('school_admin_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('school_admin_id')->constrained('users');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('status');
            $table->timestampsTz();
            $table->index(['school_admin_id', 'status']);
        });
        DB::statement("ALTER TABLE school_admin_links ADD CONSTRAINT school_admin_links_status_check CHECK (status IN ('requested','pending_confirmation','active','revoked','expired','superseded','cancelled'))");
        DB::statement('CREATE UNIQUE INDEX school_admin_links_active_unique ON school_admin_links (school_admin_id) WHERE status = \'active\'');

        // School-scoped teacher invitations (B1 right, scoped)
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained('schools');
        });

        // Narrow-only overrides (Leo amendment 1): the ONLY legal key is "deny".
        // A widening override is rejected AT WRITE TIME by the database itself.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guardian_links_overrides_narrow_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE bad_key text;
            BEGIN
                IF NEW.permission_overrides IS NULL THEN RETURN NEW; END IF;
                IF jsonb_typeof(NEW.permission_overrides) <> 'object' THEN
                    RAISE EXCEPTION 'permission_overrides must be an object (B7: narrow only)';
                END IF;
                SELECT k INTO bad_key FROM jsonb_object_keys(NEW.permission_overrides) AS t(k) WHERE k <> 'deny' LIMIT 1;
                IF bad_key IS NOT NULL THEN
                    RAISE EXCEPTION 'permission_overrides may only NARROW (key "deny"); "%" would widen and is rejected (FR006/B7)', bad_key;
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER guardian_links_overrides_guard
                BEFORE INSERT OR UPDATE ON guardian_links
                FOR EACH ROW EXECUTE FUNCTION guardian_links_overrides_narrow_only();
            SQL);

        // ── RLS: helpers inline; every 'scoped' table gets ENABLE + FORCE ──
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $superOnly = "({$role} = 'academy_admin' AND 'super_admin' = ANY({$caps}))";

        $policies = [
            'guardian_links' => "{$system} OR guardian_id::text = {$actor} OR student_id::text = {$actor} OR {$ops}
                OR ({$role} = 'school_admin' AND student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))",
            'school_links' => "{$system} OR student_id::text = {$actor} OR {$ops}
                OR ({$role} IN ('school_admin','teacher') AND school_id::text = ANY({$schools}))",
            'teacher_links' => "{$system} OR teacher_id::text = {$actor} OR {$ops}
                OR ({$role} = 'school_admin' AND school_id::text = ANY({$schools}))",
            'school_admin_links' => "{$system} OR school_admin_id::text = {$actor} OR {$ops}",
            'admin_capabilities' => "{$system} OR user_id::text = {$actor} OR {$superOnly}",
            'uploads' => "{$system} OR uploaded_by::text = {$actor} OR {$ops}",
            'guardian_replacement_exceptions' => "{$system} OR {$ops}
                OR ({$role} = 'school_admin' AND student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))",
        ];

        foreach ($policies as $table => $expression) {
            // Reads additionally admit audit_read (the S01 audit surfaces);
            // writes never do — reporting rights are not mutation rights
            $readExpression = "({$expression}) OR {$auditRead}";
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$table}_read ON {$table} FOR SELECT USING ({$readExpression})");
            DB::unprepared("CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK ({$expression})");
            DB::unprepared("CREATE POLICY {$table}_update ON {$table} FOR UPDATE USING ({$expression}) WITH CHECK ({$expression})");
            DB::unprepared("CREATE POLICY {$table}_delete ON {$table} FOR DELETE USING ({$expression})");
        }

        // audit_events: EVERY context may INSERT (BI-8 — denials from any actor
        // must be recordable); reading requires audit_read or system
        DB::unprepared('ALTER TABLE audit_events ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE audit_events FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY audit_events_read ON audit_events FOR SELECT USING ({$system} OR {$auditRead})");
        DB::unprepared('CREATE POLICY audit_events_write ON audit_events FOR INSERT WITH CHECK (true)');
    }

    public function down(): void
    {
        foreach (['guardian_links', 'school_links', 'teacher_links', 'school_admin_links', 'admin_capabilities', 'uploads', 'guardian_replacement_exceptions'] as $table) {
            foreach (['read', 'insert', 'update', 'delete'] as $kind) {
                DB::unprepared("DROP POLICY IF EXISTS {$table}_{$kind} ON {$table}");
            }
            DB::unprepared("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
        DB::unprepared('DROP POLICY IF EXISTS audit_events_read ON audit_events');
        DB::unprepared('DROP POLICY IF EXISTS audit_events_write ON audit_events');
        DB::unprepared('ALTER TABLE audit_events NO FORCE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE audit_events DISABLE ROW LEVEL SECURITY');
        DB::unprepared('DROP TRIGGER IF EXISTS guardian_links_overrides_guard ON guardian_links');
        DB::unprepared('DROP FUNCTION IF EXISTS guardian_links_overrides_narrow_only()');
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
        Schema::dropIfExists('school_admin_links');
    }
};
