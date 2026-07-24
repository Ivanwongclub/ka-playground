<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// S02B STEP 2 amendment: the team_categories five-branch verification exposed
// that guardians could not read their own students' school_links — the lobby
// subquery silently returned nothing. Correct in its own right: a guardian may
// see WHICH school their linked child attends.
return new class extends Migration
{
    public function up(): void
    {
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $ops = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        DB::unprepared('DROP POLICY IF EXISTS school_links_read ON school_links');
        DB::unprepared("CREATE POLICY school_links_read ON school_links FOR SELECT USING (
            ({$ctx} = 'system' OR student_id::text = {$actor} OR {$ops}
                OR ({$role} IN ('school_admin','teacher') AND school_id::text = ANY({$schools}))
                OR ({$role} = 'guardian' AND student_id::text = ANY({$students}))
            ) OR {$auditRead}
        )");
    }

    public function down(): void
    {
        // Reverting would re-open the guardian blind spot; recreate S02A's
        // original policy only via the S02A migration's down().
    }
};
