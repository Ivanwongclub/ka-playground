<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// S02A gate review (Leo item 2): users under RLS. Without this, any
// authenticated session could read EVERY account's name and email platform-wide
// through any query path — a data-protection defect, closed here.
//
// The auth-bootstrap window: Sanctum must resolve tokens/users BEFORE any
// context exists, and guest flows (login, invitation accept, password reset)
// legitimately run context-free. Policies therefore ADMIT the empty context
// (''), which every authenticated api request replaces via middleware before
// controllers run. Residual: a context-free DB session reads users/tokens —
// confined to the bootstrap phase by construction, recorded in AUDIT §5 with
// the SECURITY DEFINER hardening option.
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
        $bootstrap = "({$ctx} IS NULL OR {$ctx} = '')"; // guest/auth-bootstrap phase
        $system = "{$ctx} = 'system'";
        $opsish = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $auditRead = "({$role} = 'academy_admin' AND ('audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        $usersSelect = "{$bootstrap} OR {$system} OR id::text = {$actor} OR {$opsish} OR {$auditRead}
            OR ({$role} = 'academy_admin' AND role = 'academy_admin')
            OR ({$role} = 'guardian' AND id::text = ANY({$students}))
            OR ({$role} IN ('school_admin','teacher') AND (
                   id IN (SELECT sl.student_id FROM school_links sl WHERE sl.school_id::text = ANY({$schools}) AND sl.status = 'active')
                OR id IN (SELECT tl.teacher_id FROM teacher_links tl WHERE tl.school_id::text = ANY({$schools}) AND tl.status = 'active')
                OR id IN (SELECT sal.school_admin_id FROM school_admin_links sal WHERE sal.school_id::text = ANY({$schools}) AND sal.status = 'active')
            ))";
        $usersInsert = "{$bootstrap} OR {$system} OR {$opsish} OR ({$role} = 'guardian')"; // guardian-led student creation (L4)
        $usersUpdate = "{$bootstrap} OR {$system} OR id::text = {$actor} OR {$opsish}";

        DB::unprepared('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE users FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY users_read ON users FOR SELECT USING ({$usersSelect})");
        DB::unprepared("CREATE POLICY users_insert ON users FOR INSERT WITH CHECK ({$usersInsert})");
        DB::unprepared("CREATE POLICY users_update ON users FOR UPDATE USING ({$usersUpdate}) WITH CHECK ({$usersUpdate})");
        DB::unprepared("CREATE POLICY users_delete ON users FOR DELETE USING ({$system})");

        $tokenExpr = "{$bootstrap} OR {$system} OR (tokenable_type = 'App\\Models\\User' AND tokenable_id::text = {$actor})";
        DB::unprepared('ALTER TABLE personal_access_tokens ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE personal_access_tokens FORCE ROW LEVEL SECURITY');
        DB::unprepared("CREATE POLICY pat_all_read ON personal_access_tokens FOR SELECT USING ({$tokenExpr})");
        DB::unprepared("CREATE POLICY pat_insert ON personal_access_tokens FOR INSERT WITH CHECK ({$tokenExpr})");
        DB::unprepared("CREATE POLICY pat_update ON personal_access_tokens FOR UPDATE USING ({$tokenExpr}) WITH CHECK ({$tokenExpr})");
        DB::unprepared("CREATE POLICY pat_delete ON personal_access_tokens FOR DELETE USING ({$tokenExpr})");
    }

    public function down(): void
    {
        foreach (['users_read', 'users_insert', 'users_update', 'users_delete'] as $p) {
            DB::unprepared("DROP POLICY IF EXISTS {$p} ON users");
        }
        DB::unprepared('ALTER TABLE users NO FORCE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE users DISABLE ROW LEVEL SECURITY');
        foreach (['pat_all_read', 'pat_insert', 'pat_update', 'pat_delete'] as $p) {
            DB::unprepared("DROP POLICY IF EXISTS {$p} ON personal_access_tokens");
        }
        DB::unprepared('ALTER TABLE personal_access_tokens NO FORCE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE personal_access_tokens DISABLE ROW LEVEL SECURITY');
    }
};
