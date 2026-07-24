<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S02A STEP 2 — programme entity + immutable version snapshots (Spec N2, D5).
// jurisdiction per OD-16: HK and mainland share an offset, not a legal regime.
// Lifecycle transitions/publish flow are S02B; the entity carries the D5 states.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. STEM-CAR-2026
            // Admin-authored short labels: inline trilingual columns (OD-19)
            $table->string('name_en');
            $table->string('name_tc');
            $table->string('name_sc');
            $table->string('status')->default('draft'); // D5 machine; transitions built in S02B
            $table->string('jurisdiction', 2)->default('HK'); // OD-16
            $table->timestampTz('enrolment_opens_at')->nullable();
            $table->timestampTz('enrolment_closes_at')->nullable();
            $table->unsignedSmallInteger('hold_window_days')->default(7); // OD-11
            $table->string('payer_party')->default('parent'); // E6
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE programmes ADD CONSTRAINT programmes_status_check CHECK (status IN ('draft','ready','published','enrolment_closed','running','completed','archived','unpublished'))");
        DB::statement("ALTER TABLE programmes ADD CONSTRAINT programmes_jurisdiction_check CHECK (jurisdiction IN ('HK','CN'))");
        DB::statement("ALTER TABLE programmes ADD CONSTRAINT programmes_payer_party_check CHECK (payer_party IN ('parent','student','school'))");

        Schema::create('programme_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->unsignedInteger('version');
            $table->jsonb('config'); // full config snapshot at freeze time
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['programme_id', 'version']);
        });

        // Immutable once written (D5: existing enrolments keep the terms they
        // agreed to) — same DB-level pattern as BI-1
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION programme_versions_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'programme_versions is INSERT-only (D5 snapshot immutability): % blocked', TG_OP;
            END $$;

            CREATE TRIGGER programme_versions_immutable_guard
                BEFORE UPDATE OR DELETE OR TRUNCATE ON programme_versions
                FOR EACH STATEMENT EXECUTE FUNCTION programme_versions_immutable();

            REVOKE UPDATE, DELETE, TRUNCATE ON programme_versions FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS programme_versions_immutable() CASCADE;');
        Schema::dropIfExists('programme_versions');
        Schema::dropIfExists('programmes');
    }
};
