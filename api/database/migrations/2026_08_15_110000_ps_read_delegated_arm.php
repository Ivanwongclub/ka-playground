<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A-4 (sessions) — delegated RLS read arm on ps_read. ADDITIVE ONLY (READING 2), mirroring A-4 (teams). A
 * fifth read arm is APPENDED to ps_read; the existing FOUR arms (system/opsAudit/mentor_id=actor/sessionRoster)
 * are recreated CHARACTER-FOR-CHARACTER from create_sessions.php with only ` OR (<arm>)` added. No tightening
 * (future A-8). No ps_insert/update/delete change; no session_bookings/attendance change.
 *
 * The gap this opens is large: a school_admin is absent from ps_read entirely today, and a teacher reads only
 * as the ASSIGNED mentor (mentor_id=actor). The new arm lets a school_admin/teacher whose SCHOOL holds a
 * delegated enrolment.view for a PROGRAMME read that programme's sessions. enrolment.view is the cap because
 * sessions ARE enrolment delivery — the existing sessionRoster arm already keys session visibility off
 * enrolments, so the delegated arm mirrors the table's own logic.
 *
 * It gates on the request-wide GUC as a cheap prefilter, THEN narrows PER-PROGRAMME via the A-2 tables' scope-
 * join (heldFor — A-3's capabilitiesForProgramme precedence in SQL: school-specific override > all-schools >
 * baseline; withhold ⇒ deny), keyed on the actor's schools + programme_sessions.programme_id (sessions carry
 * no school column). So a withhold on programme P denies P's sessions even while enrolment.view stays in the
 * request-wide GUC — the arm honors capabilitiesForProgramme, NOT the GUC alone.
 *
 * MULTI-SCHOOL EDGE (A-4 ruling 3, same as teams): for a >=2-school actor with CONFLICTING same-level
 * (school-specific) overrides on the same (programme, capability), this SQL resolves GRANT-WINS-at-specific-
 * level while A-3 PHP resolves LAST-WINS. They AGREE for single-school actors (all tests). Canonical rule is
 * DENY-WINS; reconciled in "A-3-follow" (not changed here — A-4 stays additive + single-school-correct).
 */
return new class extends Migration
{
    public function up(): void
    {
        [$existing, $role, $caps, $schools] = $this->clauses();

        // heldFor(C): does the actor's school hold capability C for THIS session's programme, per A-3 precedence?
        $heldFor = fn (string $cap): string => "(
            EXISTS (SELECT 1 FROM programme_authority_overrides o
                    WHERE o.programme_id = programme_sessions.programme_id AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}) AND o.mode = 'grant')
            OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o
                    WHERE o.programme_id = programme_sessions.programme_id AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}))
                AND (EXISTS (SELECT 1 FROM programme_authority_overrides o
                        WHERE o.programme_id = programme_sessions.programme_id AND o.capability = '{$cap}'
                          AND o.school_id IS NULL AND o.mode = 'grant')
                     OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o
                            WHERE o.programme_id = programme_sessions.programme_id AND o.capability = '{$cap}'
                              AND o.school_id IS NULL)
                         AND EXISTS (SELECT 1 FROM school_authority_grants g
                            WHERE g.school_id::text = ANY({$schools}) AND g.capability = '{$cap}'
                              AND g.revoked_at IS NULL))))
        )";
        $delegatedArm = "({$role} IN ('school_admin','teacher')
            AND 'enrolment.view' = ANY({$caps})
            AND {$heldFor('enrolment.view')})";

        DB::unprepared('DROP POLICY ps_read ON programme_sessions');
        DB::unprepared("CREATE POLICY ps_read ON programme_sessions FOR SELECT USING ({$existing} OR ({$delegatedArm}))");
    }

    public function down(): void
    {
        // Restore the pre-A-4 (four-arm) policy verbatim.
        [$existing] = $this->clauses();
        DB::unprepared('DROP POLICY ps_read ON programme_sessions');
        DB::unprepared("CREATE POLICY ps_read ON programme_sessions FOR SELECT USING ({$existing})");
    }

    /**
     * The FOUR existing arms, character-for-character from 2026_07_29_100000_create_sessions.php — same
     * variable text, so the recreated arms are identical SQL (append-only). $schools is a NEW var used only by
     * the delegated arm; the four existing arms never reference it. Returns [existingUSING, role, caps, schools].
     */
    private function clauses(): array
    {
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $students = "string_to_array(current_setting('app.student_ids', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')"; // A-4: delegated arm only
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";
        $sessionRoster = "EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = programme_sessions.programme_id
            AND (e.student_id::text = {$actor} OR e.student_id::text = ANY({$students})))";

        $existing = "{$system} OR {$opsAudit} OR mentor_id::text = {$actor} OR ({$sessionRoster})";

        return [$existing, $role, $caps, $schools];
    }
};
