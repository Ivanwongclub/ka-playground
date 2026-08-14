<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A-4 (teams) — the FIRST delegated RLS policy arm. ADDITIVE ONLY (READING 2). A new, seventh read arm is
 * APPENDED to teams_read; the existing SIX arms (system/opsAudit/memberOf/lobbySchoolAdmin/lobbyWall/
 * teamsMentor) are recreated CHARACTER-FOR-CHARACTER from the S-MENTOR-1 source, with only ` OR (<arm>)` added.
 * No tightening of existing access (that is the future A-8, with a baseline-grant seed migration). No write-arm
 * change — teams_update stays system-only; TeamConfirmationService remains the write authority.
 *
 * The new arm opens read that no existing arm grants: a school_admin/teacher whose SCHOOL holds a delegated
 * teams.view/teams.approve for a PROGRAMME reads that programme's teams — cross-lobby (beyond lobbySchoolAdmin)
 * and un-linked (beyond teamsMentor's team_teacher_links + mentor flag). It gates on the request-wide GUC as a
 * cheap prefilter, THEN narrows PER-PROGRAMME via a programme_authority_overrides / school_authority_grants
 * scope-join that renders A-3's capabilitiesForProgramme precedence in SQL (school-specific > all-schools >
 * baseline; withhold ⇒ deny). So a withhold on programme P denies P's rows even while 'teams.approve' stays in
 * the request-wide GUC — the arm honors capabilitiesForProgramme, NOT the GUC alone.
 *
 * MULTI-SCHOOL EDGE (A-4 ruling 3): for a ≥2-school actor with CONFLICTING same-level (school-specific)
 * overrides on the same (programme, capability), this SQL resolves GRANT-WINS-at-specific-level while A-3's PHP
 * resolves LAST-WINS. They AGREE for single-school actors (all A-4/A-3 tests). The CANONICAL rule is DENY-WINS;
 * reconciling both is tracked as "A-3-follow" (not changed here — A-4 stays additive + single-school-correct).
 */
return new class extends Migration
{
    public function up(): void
    {
        [$existing, $role, $caps, $schools] = $this->clauses();

        // ── the seventh arm: delegated, per-programme, withhold-honoring ──
        // heldFor(C): does the actor's school hold capability C for THIS team's programme, per A-3 precedence?
        $heldFor = fn (string $cap): string => "(
            EXISTS (SELECT 1 FROM programme_authority_overrides o
                    WHERE o.programme_id = teams.programme_id AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}) AND o.mode = 'grant')
            OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o
                    WHERE o.programme_id = teams.programme_id AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}))
                AND (EXISTS (SELECT 1 FROM programme_authority_overrides o
                        WHERE o.programme_id = teams.programme_id AND o.capability = '{$cap}'
                          AND o.school_id IS NULL AND o.mode = 'grant')
                     OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o
                            WHERE o.programme_id = teams.programme_id AND o.capability = '{$cap}'
                              AND o.school_id IS NULL)
                         AND EXISTS (SELECT 1 FROM school_authority_grants g
                            WHERE g.school_id::text = ANY({$schools}) AND g.capability = '{$cap}'
                              AND g.revoked_at IS NULL))))
        )";
        $delegatedArm = "({$role} IN ('school_admin','teacher')
            AND ('teams.view' = ANY({$caps}) OR 'teams.approve' = ANY({$caps}))
            AND ({$heldFor('teams.view')} OR {$heldFor('teams.approve')}))";

        DB::unprepared('DROP POLICY teams_read ON teams');
        DB::unprepared("CREATE POLICY teams_read ON teams FOR SELECT USING ({$existing} OR ({$delegatedArm}))");
    }

    public function down(): void
    {
        // Restore the pre-A-4 (six-arm) policy verbatim.
        [$existing] = $this->clauses();
        DB::unprepared('DROP POLICY teams_read ON teams');
        DB::unprepared("CREATE POLICY teams_read ON teams FOR SELECT USING ({$existing})");
    }

    /**
     * The SIX existing arms, character-for-character from 2026_08_12_100000_mentor_team_access.php — same
     * variable text, so the recreated arms are identical SQL (append-only). Returns [existingUSING, role, caps, schools].
     */
    private function clauses(): array
    {
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')";
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $mentorArm = fn (string $teamIdCol, string $configOn): string => "EXISTS (SELECT 1 FROM team_teacher_links ttl WHERE ttl.team_id = {$teamIdCol} AND ttl.status = 'active' AND ttl.teacher_id::text = {$actor}) AND {$configOn}";

        $memberOf = "EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = teams.id AND tm.status <> 'removed'
            AND (tm.student_id::text = {$actor} OR tm.student_id::text = ANY({$students})))";
        $lobbySchoolAdmin = "({$role} = 'school_admin' AND EXISTS (SELECT 1 FROM team_categories c
            WHERE c.id = teams.category_id AND c.school_id::text = ANY({$schools})))";
        $lobbyWall = "status = 'forming' AND {$role} = 'student'
            AND EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = teams.programme_id
                        AND e.student_id::text = {$actor} AND e.status IN ('in_pool','teamed'))
            AND EXISTS (SELECT 1 FROM team_categories c WHERE c.id = teams.category_id
                        AND (c.school_id IS NULL OR c.school_id::text = ANY({$schools})))";
        $teamsMentor = $mentorArm('teams.id', "EXISTS (SELECT 1 FROM programmes p WHERE p.id = teams.programme_id AND p.mentor_team_access)");

        $existing = "{$system} OR {$opsAudit} OR {$memberOf} OR {$lobbySchoolAdmin} OR ({$lobbyWall}) OR ({$teamsMentor})";

        return [$existing, $role, $caps, $schools];
    }
};
