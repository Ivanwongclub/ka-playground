<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Grant-role hardening (audit 2026-07-27, separate from S04B-5): the audit
// surfaced three append-only tables that leaned on RLS-deny alone (or, for the
// global reconciliation_log, nothing). This brings payment_evidence and
// withdrawal_endorsements up to the evidence-table pattern (RLS-deny + TRIGGER
// + revoke); reconciliation_log gets its revoke in grant-app-role.sql (it is a
// global, non-RLS table, so the privilege revoke is its protection layer).
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_evidence', 'withdrawal_endorsements'] as $table) {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION {$table}_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN RAISE EXCEPTION '{$table} is append-only (grant-role audit): % blocked', TG_OP; END \$\$;
                CREATE TRIGGER {$table}_immutable_guard BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table}
                    FOR EACH STATEMENT EXECUTE FUNCTION {$table}_immutable();
                REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM PUBLIC;
                SQL);
            DB::unprepared("DO \$\$ BEGIN IF current_user <> 'kap_app' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname='kap_app') THEN REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM kap_app; END IF; END \$\$;");
        }
    }

    public function down(): void
    {
        foreach (['payment_evidence', 'withdrawal_endorsements'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_guard ON {$table}");
            DB::unprepared("DROP FUNCTION IF EXISTS {$table}_immutable()");
        }
    }
};
