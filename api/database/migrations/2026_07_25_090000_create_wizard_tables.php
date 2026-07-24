<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S02B STEP 1 — wizard state + pre-flight archive (Spec Part D).
// Classification (S02B plan): BOTH GLOBAL — readable by every authenticated
// session; no personal and no commercial data (fees/terms live in their
// scoped tables, steps 2–3). scope-map.php updated in this same commit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wizard_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->string('section_key'); // D2: basics … integration
            $table->string('status')->default('not_started'); // not_started | incomplete | complete | deferred
            $table->jsonb('data')->nullable(); // section config payload (no fees/terms — scoped tables)
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['programme_id', 'section_key']);
        });
        DB::statement("ALTER TABLE wizard_sections ADD CONSTRAINT wizard_sections_status_check CHECK (status IN ('not_started','incomplete','complete','deferred'))");

        Schema::create('pre_flight_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('programme_id')->constrained();
            $table->jsonb('findings'); // [{severity: error|warning|info, code, message}]
            $table->boolean('publishable');
            $table->foreignId('ran_by')->constrained('users');
            $table->timestampTz('ran_at')->useCurrent();
        });

        Schema::table('programmes', function (Blueprint $table) {
            $table->boolean('is_template')->default(false); // D6
        });
    }

    public function down(): void
    {
        Schema::table('programmes', function (Blueprint $table) {
            $table->dropColumn('is_template');
        });
        Schema::dropIfExists('pre_flight_results');
        Schema::dropIfExists('wizard_sections');
    }
};
