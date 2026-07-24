<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S03 STEP 2 — consent requests + signatures (Spec G4/G5, FR036).
// Classification per the S03 plan, this commit. consent_signatures is the
// strictest table on the platform: signer alone among portal roles.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('consent_templates');
            $table->foreignId('programme_id')->constrained();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('signer_id')->constrained('users'); // the addressed guardian
            $table->string('status')->default('sent'); // G5: draft|sent|viewed|signed|declined|expired|superseded
            $table->jsonb('merge_data'); // frozen at issuance — the rendered document is deterministic
            $table->jsonb('event_sequence')->default('[]'); // SERVER-recorded: opened/rendered/scrolled/signed
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE consent_requests ADD CONSTRAINT cr_status_check CHECK (status IN ('draft','sent','viewed','signed','declined','expired','superseded'))");

        Schema::create('consent_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->unique()->constrained('consent_requests');
            $table->foreignId('signer_id')->constrained('users');
            $table->string('language', 5); // the language RENDERED to the signer, server-recorded
            $table->foreignUuid('template_version_id')->constrained('consent_template_versions');
            $table->char('template_sha256', 64); // BI-6/OD-20: the version's hash, signed language
            $table->char('rendered_sha256', 64); // dual-hash: the merge-resolved document the guardian SAW
            $table->string('method'); // drawn | typed
            $table->jsonb('signature_payload'); // stroke vectors / typed name
            $table->uuid('image_upload_id')->nullable(); // PNG via the S00 upload service (BI-10)
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->jsonb('event_sequence'); // snapshot at signing (G8)
            $table->timestampTz('signed_at');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE consent_signatures ADD CONSTRAINT cs_method_check CHECK (method IN ('drawn','typed'))");

        // Immutable, period (N/BI-6): signatures are evidence
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION consent_signatures_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'consent_signatures is INSERT-only (BI-6 evidence): % blocked', TG_OP;
            END $$;
            CREATE TRIGGER consent_signatures_immutable_guard
                BEFORE UPDATE OR DELETE OR TRUNCATE ON consent_signatures
                FOR EACH STATEMENT EXECUTE FUNCTION consent_signatures_immutable();
            REVOKE UPDATE, DELETE, TRUNCATE ON consent_signatures FROM PUBLIC;
            SQL);
        DB::unprepared('REVOKE UPDATE, DELETE, TRUNCATE ON consent_signatures FROM kap_app');

        // ── RLS per the plan ──
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        // consent_requests: signer · the student (status, E5) · school_admin of
        // the student's school (chasing, H4) · ops/audit · system
        $requestsRead = "{$system} OR signer_id::text = {$actor} OR student_id::text = {$actor} OR {$opsAudit}
            OR ({$role} = 'school_admin' AND student_id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active'))";
        // writes: system (S04A per-enrolment) or academy operations (manual
        // issuance pre-S04A — an admin action, not a fixture; plan noted)
        $requestsWrite = "{$system} OR {$ops}";
        // the SIGNER updates their own request (events, status transitions)
        $requestsUpdate = "{$requestsWrite} OR signer_id::text = {$actor}";

        DB::unprepared('ALTER TABLE consent_requests ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE consent_requests FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY cr_read ON consent_requests FOR SELECT USING ({$requestsRead})");
        DB::unprepared("CREATE POLICY cr_insert ON consent_requests FOR INSERT WITH CHECK ({$requestsWrite})");
        DB::unprepared("CREATE POLICY cr_update ON consent_requests FOR UPDATE USING ({$requestsUpdate}) WITH CHECK ({$requestsUpdate})");
        DB::unprepared("CREATE POLICY cr_delete ON consent_requests FOR DELETE USING ({$system})");

        // consent_signatures: THE STRICTEST — signer alone among portal roles;
        // ops/audit for compliance; system. WITH CHECK signer_id = actor means
        // even a bypassed controller cannot write a signature as someone else.
        $signaturesRead = "{$system} OR signer_id::text = {$actor} OR {$opsAudit}";
        $signaturesInsert = "{$system} OR signer_id::text = {$actor}";
        DB::unprepared('ALTER TABLE consent_signatures ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE consent_signatures FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY cs_read ON consent_signatures FOR SELECT USING ({$signaturesRead})");
        DB::unprepared("CREATE POLICY cs_insert ON consent_signatures FOR INSERT WITH CHECK ({$signaturesInsert})");
    }

    public function down(): void
    {
        foreach (['cs_read', 'cs_insert'] as $p) {
            DB::unprepared("DROP POLICY IF EXISTS {$p} ON consent_signatures");
        }
        foreach (['cr_read', 'cr_insert', 'cr_update', 'cr_delete'] as $p) {
            DB::unprepared("DROP POLICY IF EXISTS {$p} ON consent_requests");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS consent_signatures_immutable() CASCADE');
        Schema::dropIfExists('consent_signatures');
        Schema::dropIfExists('consent_requests');
    }
};
