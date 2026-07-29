<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04C STEP 3 — orphan pairs + held links (OD-23, 2.30, Leo 1a/1b · FLAG #2).
//
// Two decisions are deliberate and stay: approving a PERSON is not approving a
// RELATIONSHIP. So all S04C linkage terminates in a PENDING state that only an
// admin's decision activates.
//
//  - guardian_links gains 'pending_approval' (the state a claimed link waits in
//    for the admin's link-approval; the FLAG #2 to_state='active' audit is
//    written only by that approval).
//  - held_links records a relationship CLAIMED on a registration form against an
//    address that has no verified account yet — "form-claimed, not confirmed by
//    either party". It has an ENUMERATED, terminal fate: materialise (only when
//    the counterpart address is later VERIFIED) or expire (TTL). Never a dead
//    loop, never a clean pending link before verification (the typo guard).
return new class extends Migration
{
    public function up(): void
    {
        // guardian_links: additive — the pending-approval state (2.30). The shipped
        // enum uses 'pending_confirmation' (the old B5 counterpart-confirmation
        // ceremony); the new model waits on an ADMIN, so it is a distinct state.
        DB::statement('ALTER TABLE guardian_links DROP CONSTRAINT guardian_links_status_check');
        DB::statement("ALTER TABLE guardian_links ADD CONSTRAINT guardian_links_status_check CHECK (status IN ('requested','pending_confirmation','pending_approval','active','revoked','expired','superseded','cancelled'))");

        Schema::create('held_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('claimant_id')->constrained('users');    // the account that named the counterpart
            $table->string('claimant_role');                            // guardian | student (fixes the eventual link direction)
            $table->string('counterpart_email');                        // the claimed, not-yet-verified address
            $table->string('counterpart_name')->nullable();
            $table->foreignId('school_id')->nullable()->constrained('schools'); // the claimant's routing (read scope)
            $table->string('status')->default('held');                  // held | materialised | expired (terminal)
            $table->string('origin')->default('form_claimed');          // "form-claimed — not confirmed by either party"
            $table->timestampTz('expires_at');
            $table->uuid('materialised_link_id')->nullable();           // the guardian_link it became, once verified
            $table->uuid('registration_request_id')->nullable();        // provenance
            $table->timestampsTz();
            $table->index(['counterpart_email', 'status']);             // materialisation lookup
            $table->index(['status', 'expires_at']);                    // the expiry sweep
        });
        DB::statement("ALTER TABLE held_links ADD CONSTRAINT held_links_status_check CHECK (status IN ('held','materialised','expired'))");
        DB::statement("ALTER TABLE held_links ADD CONSTRAINT held_links_claimant_role_check CHECK (claimant_role IN ('guardian','student'))");

        // ── RLS: read = system/ops/audit/routed-school-admin; write = SYSTEM ONLY.
        // A held link is the most misleadable row in the system (an unconfirmed
        // claim); it is created, materialised and expired only by the platform.
        $ctx = "current_setting('app.context', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $routedSchool = "({$role} = 'school_admin' AND school_id::text = ANY({$schools}))";

        DB::unprepared('ALTER TABLE held_links ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE held_links FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY held_links_read ON held_links FOR SELECT USING ({$system} OR {$ops} OR {$auditRead} OR {$routedSchool})");
        DB::unprepared("CREATE POLICY held_links_insert ON held_links FOR INSERT WITH CHECK ({$system})");
        DB::unprepared("CREATE POLICY held_links_update ON held_links FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        // No DELETE policy: held links are never deleted — they reach a terminal
        // status (materialised/expired) that stays on the record.
    }

    public function down(): void
    {
        foreach (['read', 'insert', 'update'] as $kind) {
            DB::unprepared("DROP POLICY IF EXISTS held_links_{$kind} ON held_links");
        }
        DB::unprepared('ALTER TABLE held_links NO FORCE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE held_links DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('held_links');
        DB::statement('ALTER TABLE guardian_links DROP CONSTRAINT guardian_links_status_check');
        DB::statement("ALTER TABLE guardian_links ADD CONSTRAINT guardian_links_status_check CHECK (status IN ('requested','pending_confirmation','active','revoked','expired','superseded','cancelled'))");
    }
};
