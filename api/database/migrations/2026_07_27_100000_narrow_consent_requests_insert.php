<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// S04A STEP 1 — the reversal S04A was named for (S03 AUDIT §5 item 4, Leo
// ruling 1): consent_requests INSERT narrows back to SYSTEM-ONLY now that
// issuance is automatic. The temporary ops widening from S03 ends here.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP POLICY cr_insert ON consent_requests');
        DB::unprepared("CREATE POLICY cr_insert ON consent_requests FOR INSERT WITH CHECK (current_setting('app.context', true) = 'system')");
    }

    public function down(): void
    {
        DB::unprepared('DROP POLICY cr_insert ON consent_requests');
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        DB::unprepared("CREATE POLICY cr_insert ON consent_requests FOR INSERT WITH CHECK (current_setting('app.context', true) = 'system' OR (current_setting('app.actor_role', true) = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps}))))");
    }
};
