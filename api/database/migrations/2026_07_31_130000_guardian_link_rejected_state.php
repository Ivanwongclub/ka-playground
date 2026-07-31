<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// S04D STEP 2 (2.30) — the admin-rejection terminal. The guardian-link state
// machine is requested → pending_approval → active | rejected. S04C's
// rejectLink used 'cancelled' (the only reject-like terminal then); 2.30 names
// the admin's refusal 'rejected', distinct from a student CANCELLING a ceremony
// at pending_confirmation ('cancelled' stays that). Additive CHECK extension.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE guardian_links DROP CONSTRAINT guardian_links_status_check');
        DB::statement("ALTER TABLE guardian_links ADD CONSTRAINT guardian_links_status_check CHECK (status IN ('requested','pending_confirmation','pending_approval','active','rejected','revoked','expired','superseded','cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE guardian_links DROP CONSTRAINT guardian_links_status_check');
        DB::statement("ALTER TABLE guardian_links ADD CONSTRAINT guardian_links_status_check CHECK (status IN ('requested','pending_confirmation','pending_approval','active','revoked','expired','superseded','cancelled'))");
    }
};
