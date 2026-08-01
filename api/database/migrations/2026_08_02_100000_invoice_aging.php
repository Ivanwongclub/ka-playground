<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S04F STEP 3 (OD-25 · OD-55) — the school-settled receivable's clock + the
 * one-invoice-per-pair hardening.
 *
 *  - UNIQUE (school_id, programme_id): one consolidated invoice per pair is now
 *    STRUCTURAL — double-create is impossible, not merely avoided by the serial
 *    consumer. coverOrder re-reads the winner on the constraint.
 *  - due_at: the receivable's clock, set ONCE at issuance (now + terms). Adding
 *    covered orders grows `original` but MUST NOT move due_at — a school cannot
 *    defer forever by triggering more enrolments. Only extendTerms moves it.
 *  - status gains `overdue`: the aging sweep flags an unpaid invoice past
 *    due_at + grace. `overdue` IS the trackable academy exception (status is the
 *    ledger, D-13) — terminal fates paid / issued(extend).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE consolidated_invoices ADD COLUMN due_at timestamptz NULL');
        DB::statement('CREATE UNIQUE INDEX consolidated_invoices_school_programme_unique ON consolidated_invoices (school_id, programme_id)');
        DB::statement('ALTER TABLE consolidated_invoices DROP CONSTRAINT ci_status_check');
        DB::statement("ALTER TABLE consolidated_invoices ADD CONSTRAINT ci_status_check CHECK (status IN ('issued','paid','overdue'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE consolidated_invoices DROP CONSTRAINT ci_status_check');
        DB::statement("ALTER TABLE consolidated_invoices ADD CONSTRAINT ci_status_check CHECK (status IN ('issued','paid'))");
        DB::statement('DROP INDEX consolidated_invoices_school_programme_unique');
        DB::statement('ALTER TABLE consolidated_invoices DROP COLUMN due_at');
    }
};
