<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SR010 / Spec P3: nightly reconciliation results. One row per assertion per run,
// plus a '_run' summary row per run (P4: every scheduled job logs a run record).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->string('assertion_key'); // '_run' = run summary row
            $table->json('tags');
            $table->boolean('passed');
            $table->text('details')->nullable();
            $table->string('cites')->nullable(); // BI / requirement IDs the assertion proves
            $table->unsignedInteger('duration_ms');
            $table->timestampTz('ran_at');

            $table->index('run_id');
            $table->index(['assertion_key', 'ran_at']);
            $table->index(['passed', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_log');
    }
};
