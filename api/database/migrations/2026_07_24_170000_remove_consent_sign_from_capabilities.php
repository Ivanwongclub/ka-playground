<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// DEFECT (Leo review, 24 Jul): no capability group may carry consent.sign —
// consent is a legal act by a named guardian (FR036, ETO Cap. 553, BI-6).
// Fresh databases now seed correctly from the matrix; this corrects any
// database seeded before the fix. No staging/production environment exists.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('capability_permissions')->where('permission_key', 'consent.sign')->delete();
    }

    public function down(): void
    {
        // Deliberately irreversible: reintroducing a staff-signable consent is
        // the defect. The nightly assertion authz.consent_sign_exclusive guards it.
    }
};
