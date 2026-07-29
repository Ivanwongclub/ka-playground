<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04C STEP 1 — the platform's FIRST anonymous WRITE (OD-23, D-iii).
// `registration_requests` is written by a genuinely new `public` scope context
// that is CONFINED at the database: it can INSERT this one table and nothing
// else, and the WITH CHECK constrains WHAT it may insert (only a fresh
// 'submitted' row — never one pre-claiming approval or a reviewer). No SELECT
// admits it (no enumeration oracle). `scope.public_context_confinement` proves
// all of this structurally. This is the single_reader discipline of the payment
// link, applied to a writer — NOT laundered through asSystem.
return new class extends Migration
{
    public function up(): void
    {
        // Opt-in public listing of partner schools (default OFF) — the registration
        // school picker exposes ONLY listed schools, by explicit query filter, never
        // by granting the public context a SELECT (schools has no RLS; the filter is
        // the boundary). Unlisted schools' families are invited directly, as today.
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('public_listing')->default(false);
        });

        Schema::create('registration_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind');                 // student | guardian
            $table->string('applicant_name');
            $table->string('applicant_email');
            $table->string('applicant_phone')->nullable();
            $table->string('preferred_language');   // en | zh-TC | zh-SC
            $table->date('date_of_birth')->nullable();
            $table->string('routing');              // school | academy
            $table->foreignId('school_id')->nullable()->constrained('schools'); // routed school; NULL when routing = academy
            $table->string('counterpart_email')->nullable();
            $table->string('counterpart_name')->nullable();
            $table->string('status')->default('submitted'); // submitted | approved | declined
            $table->string('reference')->unique();  // opaque acknowledgement; NOT publicly queryable (no status endpoint)
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->foreignId('created_account_id')->nullable()->constrained('users'); // set at approval (STEP 2)
            $table->timestampsTz();
            $table->index(['status', 'created_at']); // the approval queue reads this
            $table->index(['school_id', 'status']);  // routed-school reads
        });

        DB::statement("ALTER TABLE registration_requests ADD CONSTRAINT rr_kind_check CHECK (kind IN ('student','guardian'))");
        DB::statement("ALTER TABLE registration_requests ADD CONSTRAINT rr_language_check CHECK (preferred_language IN ('en','zh-TC','zh-SC'))");
        DB::statement("ALTER TABLE registration_requests ADD CONSTRAINT rr_status_check CHECK (status IN ('submitted','approved','declined'))");
        DB::statement("ALTER TABLE registration_requests ADD CONSTRAINT rr_routing_check CHECK (routing IN ('school','academy'))");
        // No free-text / unlisted-school gap (OD-23): a school routing carries a school,
        // an academy routing does not — enforced by the database, not the form.
        DB::statement("ALTER TABLE registration_requests ADD CONSTRAINT rr_routing_school_check CHECK ((routing = 'school') = (school_id IS NOT NULL))");

        // ── RLS: fail-closed, FORCE so owners obey (matches the scope layer) ──
        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        // A school admin sees ONLY school-routed requests for their own school.
        // Direct (academy-routed) registrations are academy-only (ops/audit/system).
        $routedSchool = "({$role} = 'school_admin' AND routing = 'school' AND school_id::text = ANY({$schools}))";

        DB::unprepared('ALTER TABLE registration_requests ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE registration_requests FORCE ROW LEVEL SECURITY');

        // THE ONE POLICY that admits the public context, platform-wide. WITH CHECK
        // pins WHAT public may insert: a fresh 'submitted' row with no reviewer and
        // no linked account — no privilege escalation through the insert. `system`
        // may also insert (seeds, STEP-2 follow-ups). Nothing else writes here.
        DB::unprepared("CREATE POLICY registration_requests_insert ON registration_requests FOR INSERT WITH CHECK (
            {$system}
            OR (
                {$ctx} = 'public'
                AND status = 'submitted'
                AND reviewed_by IS NULL
                AND reviewed_at IS NULL
                AND decline_reason IS NULL
                AND created_account_id IS NULL
            )
        )");

        // Reads/updates: the reviewer set only — the public context appears in NEITHER.
        DB::unprepared("CREATE POLICY registration_requests_read ON registration_requests FOR SELECT USING (
            {$system} OR {$ops} OR {$auditRead} OR {$routedSchool}
        )");
        DB::unprepared("CREATE POLICY registration_requests_update ON registration_requests FOR UPDATE USING (
            {$system} OR {$ops} OR {$routedSchool}
        ) WITH CHECK (
            {$system} OR {$ops} OR {$routedSchool}
        )");
        // No DELETE policy: a submitted registration is never deleted (declined is a
        // status, an audited decision — not a row removal). FORCE + no policy = deny.
    }

    public function down(): void
    {
        foreach (['insert', 'read', 'update'] as $kind) {
            DB::unprepared("DROP POLICY IF EXISTS registration_requests_{$kind} ON registration_requests");
        }
        DB::unprepared('ALTER TABLE registration_requests NO FORCE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE registration_requests DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('registration_requests');
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('public_listing');
        });
    }
};
