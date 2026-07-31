<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S04E STEP 2 — additive columns for the commit (D-10 + commit idempotency).
 * A NEW migration, not an edit to the STEP 1 one: nullable/forward-compat only.
 *
 *  - enrolment_batches.programme_id  — the cohort's enrolment target, captured
 *    at the STEP 1 upload (D-10). Nullable so the STEP 1 rows already migrated
 *    are valid; the upload endpoint now requires it.
 *  - enrolment_batches.payer_party / payer_school_id — OD-25 forward-compat
 *    (school payer). Nullable; not consulted until orders exist (S05).
 *  - enrolment_batch_rows.enrolment_id — the enrolment produced for this row
 *    (null until enrolled). Its presence is the per-row idempotency marker.
 *  - enrolment_batch_rows.committed — true once the row reaches a terminal
 *    commit outcome (enrolled). A re-commit skips committed rows and
 *    re-evaluates the rest LIVE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrolment_batches', function (Blueprint $table) {
            $table->foreignId('programme_id')->nullable()->after('school_id')->constrained('programmes');
            $table->string('payer_party')->nullable()->after('programme_id');
            $table->foreignId('payer_school_id')->nullable()->after('payer_party')->constrained('schools');
            $table->unsignedInteger('enrolled_count')->default(0)->after('existing_count');
            $table->unsignedInteger('not_enrolled_count')->default(0)->after('enrolled_count');
            $table->timestampTz('committed_at')->nullable()->after('validated_at');
        });

        Schema::table('enrolment_batch_rows', function (Blueprint $table) {
            $table->uuid('enrolment_id')->nullable()->after('matched_user_id');
            $table->boolean('committed')->default(false)->after('enrolment_id');
        });
    }

    public function down(): void
    {
        Schema::table('enrolment_batch_rows', function (Blueprint $table) {
            $table->dropColumn(['enrolment_id', 'committed']);
        });
        Schema::table('enrolment_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_id');
            $table->dropConstrainedForeignId('payer_school_id');
            $table->dropColumn(['payer_party', 'enrolled_count', 'not_enrolled_count', 'committed_at']);
        });
    }
};
