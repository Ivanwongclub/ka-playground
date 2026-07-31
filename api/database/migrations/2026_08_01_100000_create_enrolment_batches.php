<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S04E STEP 1 — bulk-enrolment intake (Spec Part H, H1).
 *
 *  - enrolment_batches: a school's cohort operation. State machine (STEP 1
 *    reaches Ready or Failed; commit is STEP 2):
 *      draft → uploaded → scanning → validating → ready
 *                                             \→ failed  (scan / structural)
 *      (committing → complete | partially_complete come in STEP 2)
 *  - enrolment_batch_rows: per-row child data + disposition. STEP 1 is a
 *    DRY RUN — rows carry validated/skipped/failed with a reason and a
 *    match_existing|new disposition, but NOTHING is created (no accounts, no
 *    enrolments) until STEP 2 commit.
 *
 * Both scoped (RLS): read = system · the OWNING school's admins · academy
 * ops/finance/audit. Write = system. school_id is denormalised onto the rows so
 * the row policy is a direct predicate, not a subquery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrolment_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('school_id')->constrained('schools');
            $table->uuid('upload_id')->nullable();               // the batch-csv upload (BI-10 gate)
            $table->string('status')->default('draft');
            $table->string('failure_reason')->nullable();        // set when status = failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('new_count')->default(0);    // disposition = new (→ create() at commit)
            $table->unsignedInteger('existing_count')->default(0); // disposition = match_existing
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
            $table->index(['school_id', 'status']);
        });

        Schema::create('enrolment_batch_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->foreignId('school_id')->constrained('schools'); // denormalised for RLS
            $table->unsignedInteger('row_number');                  // 1-based data row (excludes header)
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('pending');           // pending|validated|skipped|failed
            $table->string('disposition')->nullable();              // match_existing|new
            $table->string('reason')->nullable();                   // required for skipped|failed (P4)
            $table->foreignId('matched_user_id')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->foreign('batch_id')->references('id')->on('enrolment_batches')->cascadeOnDelete();
            $table->index(['batch_id', 'status']);
        });

        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $owningAdmin = "({$role} = 'school_admin' AND school_id::text = ANY({$schools}))";
        $staffRead = "({$role} = 'academy_admin' AND ("
            ."'operations' = ANY({$caps}) OR 'finance' = ANY({$caps}) "
            ."OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $read = "{$system} OR {$owningAdmin} OR {$staffRead}";

        foreach (['enrolment_batches', 'enrolment_batch_rows'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY {$t}_read ON {$t} FOR SELECT USING ({$read})");
            DB::unprepared("CREATE POLICY {$t}_insert ON {$t} FOR INSERT WITH CHECK ({$system})");
            DB::unprepared("CREATE POLICY {$t}_update ON {$t} FOR UPDATE USING ({$system}) WITH CHECK ({$system})");
        }
    }

    public function down(): void
    {
        foreach (['enrolment_batch_rows', 'enrolment_batches'] as $t) {
            foreach (['read', 'insert', 'update'] as $k) {
                DB::unprepared("DROP POLICY IF EXISTS {$t}_{$k} ON {$t}");
            }
        }
        Schema::dropIfExists('enrolment_batch_rows');
        Schema::dropIfExists('enrolment_batches');
    }
};
