<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S01 STEP 5 — pairing codes (B4, 2.13) + guardian replacement exceptions (2.2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('student_id')->constrained('users');
            $table->string('code', 6); // 6-char alphanumeric, case-sensitive (B4)
            $table->timestampTz('expires_at'); // 7 days
            $table->timestampTz('used_at')->nullable(); // first successful use consumes it
            $table->timestampTz('invalidated_at')->nullable(); // 10 global fails (2.13)
            $table->timestampTz('created_at')->useCurrent();

            $table->index('code');
            $table->index(['student_id', 'expires_at']);
        });

        // Global failed-attempt ledger per code STRING (2.13): "global" = across
        // all accounts, in contrast to the per-account throttle. A real code whose
        // string reaches 10 failures is hard-invalidated.
        Schema::create('pairing_code_failures', function (Blueprint $table) {
            $table->string('code', 6)->primary();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at');
        });

        Schema::create('guardian_replacement_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('student_id')->constrained('users');
            $table->uuid('revoked_link_id');
            $table->text('reason');
            $table->timestampTz('deadline'); // +14 days (2.2)
            $table->string('status')->default('open'); // open | resolved | expired_suspended
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['status', 'deadline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_replacement_exceptions');
        Schema::dropIfExists('pairing_code_failures');
        Schema::dropIfExists('pairing_codes');
    }
};
