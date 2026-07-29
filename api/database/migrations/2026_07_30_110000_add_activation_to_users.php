<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S04C STEP 2 — account activation (OD-29, Leo model B 2026-07-30). A self-
// registered account, created UNVERIFIED at approval, is activated by a single
// verify-and-set-password act. The activation credential is a single-use,
// expiring, 256-bit token — only its sha256 hash is stored (never plaintext),
// exactly like invitations and payment links. No secret ever touches the
// anonymous registration surface: the token is minted at APPROVAL and delivered
// to the applicant's own (as-yet-unverified) address.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('activation_token_hash', 64)->nullable();
            $table->timestampTz('activation_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['activation_token_hash', 'activation_expires_at']);
        });
    }
};
