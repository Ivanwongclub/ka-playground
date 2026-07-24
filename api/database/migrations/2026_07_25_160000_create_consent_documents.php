<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S03 STEP 3 — signed PDF + audit certificate (FR038). Classification per the
// S03 plan: same read set as consent_signatures, plus the signer downloads
// their own copy. INSERT-only: derived evidence is still evidence.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('signature_id')->unique()->constrained('consent_signatures');
            $table->foreignUuid('request_id')->constrained('consent_requests');
            $table->foreignId('signer_id')->constrained('users');
            $table->string('language', 5);
            $table->uuid('pdf_upload_id'); // rides the S00 upload service (BI-10)
            $table->char('pdf_sha256', 64);
            $table->string('generator'); // e.g. "mpdf/mpdf 8.3.1" — on the record (Leo condition 4)
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION consent_documents_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'consent_documents is INSERT-only (FR038 evidence): % blocked', TG_OP;
            END $$;
            CREATE TRIGGER consent_documents_immutable_guard
                BEFORE UPDATE OR DELETE OR TRUNCATE ON consent_documents
                FOR EACH STATEMENT EXECUTE FUNCTION consent_documents_immutable();
            REVOKE UPDATE, DELETE, TRUNCATE ON consent_documents FROM PUBLIC;
            SQL);
        // Conditional for the same reason as consent_signatures: in kap_test
        // the app role owns the table and RI checks need its UPDATE privilege;
        // the trigger is the guarantee there. Real environments revoke.
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF current_user <> 'kap_app' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'kap_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON consent_documents FROM kap_app;
                END IF;
            END $$;
            SQL);

        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        DB::unprepared('ALTER TABLE consent_documents ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE consent_documents FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY cd_read ON consent_documents FOR SELECT USING ({$system} OR signer_id::text = {$actor} OR {$opsAudit})");
        // generation runs only in the queued job's system context
        DB::unprepared("CREATE POLICY cd_insert ON consent_documents FOR INSERT WITH CHECK ({$system})");
    }

    public function down(): void
    {
        DB::unprepared('DROP POLICY IF EXISTS cd_read ON consent_documents');
        DB::unprepared('DROP POLICY IF EXISTS cd_insert ON consent_documents');
        DB::unprepared('DROP FUNCTION IF EXISTS consent_documents_immutable() CASCADE');
        Schema::dropIfExists('consent_documents');
    }
};
