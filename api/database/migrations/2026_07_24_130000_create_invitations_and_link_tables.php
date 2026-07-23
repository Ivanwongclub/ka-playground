<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// S01 STEP 2 — invitation-only onboarding (2.11/L4) + the relationship link
// entities (Spec B2/B5/N1). Link FLOWS (pairing codes etc.) land in step 5;
// the entities live here because onboarding creates the first guardian link.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('role'); // guardian | teacher | school_admin | academy_admin — never student (guardian-led) or member (OD-22)
            $table->string('token_hash', 64)->unique(); // sha256 of the single-use token; plain token never stored
            $table->foreignId('issued_by')->constrained('users');
            $table->timestampTz('expires_at'); // issued + 14 days (2.11)
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('role')->references('key')->on('roles');
            $table->index(['email', 'role']);
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            // Admin-authored short labels: inline trilingual columns (OD-19)
            $table->string('name_en');
            $table->string('name_tc');
            $table->string('name_sc');
            $table->timestampsTz();
        });

        $linkStates = "'requested','pending_confirmation','active','revoked','expired','superseded','cancelled'";

        Schema::create('guardian_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('guardian_id')->constrained('users');
            $table->string('status'); // B5 machine; every transition audited
            $table->timestampTz('verified_at')->nullable();
            $table->jsonb('permission_overrides')->nullable(); // B7 layer 2
            $table->string('origin'); // onboarding | pairing_code | parent_initiated | school_mediated
            $table->timestampsTz();

            $table->index(['student_id', 'status']);
            $table->index(['guardian_id', 'status']);
        });
        // One ACTIVE link per (student, guardian) pair
        DB::statement('CREATE UNIQUE INDEX guardian_links_active_unique ON guardian_links (student_id, guardian_id) WHERE status = \'active\'');
        DB::statement("ALTER TABLE guardian_links ADD CONSTRAINT guardian_links_status_check CHECK (status IN ({$linkStates}))");

        Schema::create('school_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('status');
            $table->timestampsTz();
            $table->index(['student_id', 'status']);
        });
        // B2: many-to-ONE active — a student has at most one active school link
        DB::statement('CREATE UNIQUE INDEX school_links_active_unique ON school_links (student_id) WHERE status = \'active\'');
        DB::statement("ALTER TABLE school_links ADD CONSTRAINT school_links_status_check CHECK (status IN ({$linkStates}))");

        Schema::create('teacher_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('status');
            $table->timestampsTz();
            $table->index(['teacher_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX teacher_links_active_unique ON teacher_links (teacher_id) WHERE status = \'active\'');
        DB::statement("ALTER TABLE teacher_links ADD CONSTRAINT teacher_links_status_check CHECK (status IN ({$linkStates}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_links');
        Schema::dropIfExists('school_links');
        Schema::dropIfExists('guardian_links');
        Schema::dropIfExists('schools');
        Schema::dropIfExists('invitations');
    }
};
