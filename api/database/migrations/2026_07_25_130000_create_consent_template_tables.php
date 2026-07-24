<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S03 STEP 1 — consent templates + LANGUAGE-SCOPED versions (OD-20/OD-20a).
// One version row per language, its own SHA-256; published rows immutable at
// the DB (BI-6 rests on them). Classification per the S03 plan, this commit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_en');
            $table->string('name_tc');
            $table->string('name_sc');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('consent_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('consent_templates');
            $table->string('language', 5); // en | zh-TC | zh-SC (OD-20)
            $table->unsignedInteger('version');
            $table->text('body_html');
            $table->char('sha256', 64)->nullable(); // computed at publish, then frozen
            $table->string('status')->default('draft'); // draft | published
            $table->boolean('is_placeholder')->default(false); // R15
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            $table->unique(['template_id', 'language', 'version']);
        });
        DB::statement("ALTER TABLE consent_template_versions ADD CONSTRAINT ctv_language_check CHECK (language IN ('en','zh-TC','zh-SC'))");
        DB::statement("ALTER TABLE consent_template_versions ADD CONSTRAINT ctv_status_check CHECK (status IN ('draft','published'))");

        // Published versions are immutable (BI-6 hashes rest on them). Drafts
        // stay editable; the draft->published transition is the last write.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION consent_template_versions_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'published consent template versions are immutable (BI-6/OD-20): % blocked', TG_OP;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER consent_template_versions_freeze
                BEFORE UPDATE OR DELETE ON consent_template_versions
                FOR EACH ROW EXECUTE FUNCTION consent_template_versions_frozen();
            SQL);

        // RLS per the plan: bound parties read PUBLISHED versions of templates
        // SELECTED by PUBLISHED programmes; drafts academy-only; Members nothing
        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $staff = "({$role} = 'academy_admin' AND ('configuration' = ANY({$caps}) OR 'operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $config = "({$role} = 'academy_admin' AND ('configuration' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $boundParty = "(status = 'published' AND {$ctx} = 'request' AND {$role} IN ('guardian','student','school_admin')
            AND EXISTS (SELECT 1 FROM programmes p JOIN wizard_sections ws ON ws.programme_id = p.id AND ws.section_key = 'consent'
                        WHERE p.status = 'published' AND ws.data->>'template_ref' = consent_template_versions.template_id::text))";

        DB::unprepared('ALTER TABLE consent_template_versions ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE consent_template_versions FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY ctv_read ON consent_template_versions FOR SELECT USING ({$system} OR {$staff} OR {$boundParty})");
        DB::unprepared("CREATE POLICY ctv_insert ON consent_template_versions FOR INSERT WITH CHECK ({$system} OR {$config})");
        DB::unprepared("CREATE POLICY ctv_update ON consent_template_versions FOR UPDATE USING ({$system} OR {$config}) WITH CHECK ({$system} OR {$config})");
        DB::unprepared("CREATE POLICY ctv_delete ON consent_template_versions FOR DELETE USING ({$system} OR {$config})");
    }

    public function down(): void
    {
        foreach (['read', 'insert', 'update', 'delete'] as $kind) {
            DB::unprepared("DROP POLICY IF EXISTS ctv_{$kind} ON consent_template_versions");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS consent_template_versions_freeze ON consent_template_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS consent_template_versions_frozen()');
        Schema::dropIfExists('consent_template_versions');
        Schema::dropIfExists('consent_templates');
    }
};
