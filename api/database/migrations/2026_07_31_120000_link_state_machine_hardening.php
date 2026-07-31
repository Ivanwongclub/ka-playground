<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S04D STEP 1 — the 2.30 state-machine hardening across all three link tables.
//
//  - school_links / teacher_links gain 'pending_approval' (forward-compat) and an
//    'origin' column (provenance — vouch/invitation/registration/bulk, forever).
//    Neither had either (guardian_links got them in S04C). They are NOT a
//    symmetric ceremony machine: they are ADMIN-AUTHORITY-activated (D-i / vouch /
//    invitation / bulk), so no Phase-1 pending ceremony is added (D-i-3).
//  - Write policies hardened: ONLY the system context may write status='active'.
//    A non-system/non-admin actor may write non-active rows within its scope, but
//    the transition TO active is refused at the DB. Every sanctioned activation
//    path (approveLink, D-i approve, schoolVouch, invitation, bulk) already runs
//    inside an allowlisted asSystem elevation, so all five still activate.
//  - Backfill: every existing ACTIVE link that lacks a to_state='active' audit
//    gets a 'link.legacy_approved' audit KEYED ON ITS REAL created_at (not a
//    fabricated 'now'), so links.no_active_without_approval is green from run one.
return new class extends Migration
{
    public function up(): void
    {
        // ── enum + origin (school_links, teacher_links) ──
        $states = "'requested','pending_confirmation','pending_approval','active','revoked','expired','superseded','cancelled'";
        DB::statement('ALTER TABLE school_links DROP CONSTRAINT school_links_status_check');
        DB::statement("ALTER TABLE school_links ADD CONSTRAINT school_links_status_check CHECK (status IN ({$states}))");
        DB::statement('ALTER TABLE teacher_links DROP CONSTRAINT teacher_links_status_check');
        DB::statement("ALTER TABLE teacher_links ADD CONSTRAINT teacher_links_status_check CHECK (status IN ({$states}))");
        Schema::table('school_links', fn (Blueprint $t) => $t->string('origin')->nullable());   // registration_approval | vouch | bulk
        Schema::table('teacher_links', fn (Blueprint $t) => $t->string('origin')->nullable());   // invitation

        // NOTE (build finding, 2026-07-31): the write-policy hardening that belongs
        // here was found to break two SANCTIONED paths that currently write
        // status='active' in a NON-system context — PairingService::confirm (the
        // student's context; retrofitted to pending_approval in STEP 2) and
        // LinkController::schoolVouch (the school_admin's context, relying on the
        // RLS school_admin clause; STEP 3). The hardening is therefore coupled to
        // those retrofits and is DEFERRED per Leo's ruling — see PROPOSED review /
        // the step plan. This migration ships the enum + origin + backfill only.

        // ── backfill: audit-less active links get a legacy-approved activation ──
        // audit, keyed on the link's ACTUAL created_at (honest: it was active as of
        // its creation). Skips links that already carry a to_state='active' audit
        // (S04C approveLink guardian_links, D-i school_links, seeded links).
        foreach (['guardian_link' => 'guardian_links', 'school_link' => 'school_links', 'teacher_link' => 'teacher_links'] as $entity => $table) {
            DB::unprepared(
                "INSERT INTO audit_events (event_id, occurred_at, entity_type, entity_id, action, to_state, actor_role, request_id)
                 SELECT gen_random_uuid(), l.created_at, '{$entity}', l.id::text, 'link.legacy_approved', 'active', 'system', gen_random_uuid()
                 FROM {$table} l
                 WHERE l.status = 'active'
                   AND NOT EXISTS (
                       SELECT 1 FROM audit_events ae
                       WHERE ae.entity_type = '{$entity}' AND ae.entity_id = l.id::text AND ae.to_state = 'active'
                   )"
            );
        }
    }

    public function down(): void
    {
        Schema::table('school_links', fn (Blueprint $t) => $t->dropColumn('origin'));
        Schema::table('teacher_links', fn (Blueprint $t) => $t->dropColumn('origin'));
        $old = "'requested','pending_confirmation','active','revoked','expired','superseded','cancelled'";
        DB::statement('ALTER TABLE school_links DROP CONSTRAINT school_links_status_check');
        DB::statement("ALTER TABLE school_links ADD CONSTRAINT school_links_status_check CHECK (status IN ({$old}))");
        DB::statement('ALTER TABLE teacher_links DROP CONSTRAINT teacher_links_status_check');
        DB::statement("ALTER TABLE teacher_links ADD CONSTRAINT teacher_links_status_check CHECK (status IN ({$old}))");
    }
};
