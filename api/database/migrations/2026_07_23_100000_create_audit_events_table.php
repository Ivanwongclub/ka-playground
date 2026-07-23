<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// BI-1: audit_events is INSERT-only, enforced at the database level.
// Columns per Spec P2. Immutable tables carry occurred_at/created context only (Spec N).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->unsignedBigInteger('actor_id')->nullable(); // unconstrained: an audit row must outlive any actor row
            $table->string('actor_role')->nullable();
            $table->unsignedBigInteger('on_behalf_of')->nullable();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->string('action');
            $table->text('reason')->nullable();
            $table->json('payload_before')->nullable();
            $table->json('payload_after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('occurred_at');
            $table->index('actor_id');
            $table->index('action');
            $table->index('programme_id');
        });

        // DB-level INSERT-only enforcement. Statement-level on Postgres so even a
        // zero-row UPDATE/DELETE/TRUNCATE is rejected; row-level on sqlite (tests).
        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared(<<<'SQL'
                CREATE FUNCTION audit_events_immutable() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events is INSERT-only (BI-1): % blocked', TG_OP;
                END $$;

                CREATE TRIGGER audit_events_immutable_guard
                    BEFORE UPDATE OR DELETE OR TRUNCATE ON audit_events
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_events_immutable();

                -- N11: UPDATE/DELETE revoked. The table owner bypasses grants, so the
                -- trigger above is the load-bearing guard; production runs the app as a
                -- non-owner role (deploy runbook, 2.26).
                REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM PUBLIC;
                SQL),
            'sqlite' => DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events
                BEGIN SELECT RAISE(ABORT, 'audit_events is INSERT-only (BI-1): UPDATE blocked'); END;

                CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events
                BEGIN SELECT RAISE(ABORT, 'audit_events is INSERT-only (BI-1): DELETE blocked'); END;
                SQL),
            default => throw new RuntimeException(
                'audit_events immutability enforcement not implemented for driver: '.DB::getDriverName()
            ),
        };
    }

    public function down(): void
    {
        // Pre-first-deploy only (S00 rollback policy: git revert). Dropping the table
        // in any deployed environment is a CLAUDE.md §4 stop condition.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS audit_events_immutable() CASCADE;');
        }
        Schema::dropIfExists('audit_events');
    }
};
