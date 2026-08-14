<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A-4 (assessments) — delegated RLS read arms on the TWO assessment policies. ADDITIVE ONLY (READING 2).
 * READING (ii), EMBARGOED: a delegated school sees released results exactly like a guardian — nothing before
 * release. Reading (i) — a delegated school seeing UNRELEASED grades — is the deferred A-9 (assessment-grading
 * delegation: a new assessment.grade permission + delegability/embargo-bypass ruling + the grade WRITE arm).
 *
 * assessments_read (schedule/EXISTS, NO embargo): append a delegated arm gated on enrolment.view (the schedule
 *   is delivery, like a session schedule — A-4 sessions used enrolment.view), per-programme scope-join + withhold.
 * assessment_results_read (grades, EMBARGOED): append a delegated arm gated on student_records.view (a grade IS
 *   a student record) AND the SAME $released embargo the family arms use. A guardian holds student_records.view
 *   yet is embargoed on this table, so the delegated student_records.view arm is embargoed identically.
 *
 * THE EMBARGO IS ONE DEFINITION: $released is built ONCE (character-for-character from create_assessments.php:68)
 * and referenced by ownReleased, guardianReleased AND the delegated results arm — never a second copy that could
 * drift and silently break the embargo.
 *
 * PROGRAMME PATH: assessments.programme_id is a direct column (schedule heldFor keys on it). assessment_results
 * has NO programme_id — it reaches its programme (and its embargo status) via assessment_id → assessments, so the
 * results heldFor JOINs assessments for the override scope (o.programme_id = a.programme_id); the baseline school
 * grant stays school-wide (no programme join).
 *
 * MULTI-SCHOOL EDGE (A-4 ruling 3, same as teams/sessions): conflicting same-level overrides resolve GRANT-WINS
 * in this SQL / LAST-WINS in A-3 PHP; agree for single-school (all tests); canonical DENY-WINS tracked in A-3-follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        [$schedExisting, $resultsExisting, $released, $role, $caps, $schools] = $this->clauses();

        // ── assessments_read (schedule) — delegated arm keyed on the DIRECT assessments.programme_id ──
        // Ruling A: gate on enrolment.view OR student_records.view. student_records.view must open the SCHEDULE
        // too, because the delegated RESULTS arm's embargo ($released) and heldForResults read `assessments`
        // under RLS — a grade-reader can't check "released" without reading the assessment. enrolment.view keeps
        // the sessions parallel; student_records.view alone then enables released results end-to-end.
        $heldSchedEnrol = $this->heldFor('enrolment.view', 'assessments.programme_id', $schools);
        $heldSchedRecords = $this->heldFor('student_records.view', 'assessments.programme_id', $schools);
        $delegatedSchedule = "({$role} IN ('school_admin','teacher')
            AND ('enrolment.view' = ANY({$caps}) OR 'student_records.view' = ANY({$caps}))
            AND ({$heldSchedEnrol} OR {$heldSchedRecords}))";
        DB::unprepared('DROP POLICY assessments_read ON assessments');
        DB::unprepared("CREATE POLICY assessments_read ON assessments FOR SELECT USING ({$schedExisting} OR ({$delegatedSchedule}))");

        // ── assessment_results_read (grades, EMBARGOED) — heldFor JOINs assessments; then the SAME $released ──
        $heldResults = $this->heldForResults('student_records.view', $schools);
        $delegatedResults = "({$role} IN ('school_admin','teacher') AND 'student_records.view' = ANY({$caps}) AND {$heldResults} AND {$released})";
        DB::unprepared('DROP POLICY assessment_results_read ON assessment_results');
        DB::unprepared("CREATE POLICY assessment_results_read ON assessment_results FOR SELECT USING ({$resultsExisting} OR ({$delegatedResults}))");
    }

    public function down(): void
    {
        // Restore both pre-A-4 policies verbatim (existing arms only).
        [$schedExisting, $resultsExisting] = $this->clauses();
        DB::unprepared('DROP POLICY assessments_read ON assessments');
        DB::unprepared("CREATE POLICY assessments_read ON assessments FOR SELECT USING ({$schedExisting})");
        DB::unprepared('DROP POLICY assessment_results_read ON assessment_results');
        DB::unprepared("CREATE POLICY assessment_results_read ON assessment_results FOR SELECT USING ({$resultsExisting})");
    }

    /**
     * The existing arms of BOTH policies, character-for-character from 2026_07_29_120000_create_assessments.php.
     * $released is built ONCE here and reused by the results existing arms AND the delegated results arm (up()).
     * @return array{0:string,1:string,2:string,3:string,4:string,5:string} [schedExisting, resultsExisting, released, role, caps, schools]
     */
    private function clauses(): array
    {
        $ctx = "current_setting('app.context', true)";
        $actor = "current_setting('app.actor_id', true)";
        $role = "current_setting('app.actor_role', true)";
        $caps = "string_to_array(current_setting('app.capabilities', true), ',')";
        $schools = "string_to_array(current_setting('app.school_ids', true), ',')"; // A-4: delegated arms only
        $system = "{$ctx} = 'system'";
        $opsAudit = "({$role} = 'academy_admin' AND ('operations' = ANY({$caps}) OR 'audit_read' = ANY({$caps}) OR 'super_admin' = ANY({$caps})))";

        // assessments_read existing arms (create_assessments.php:51-53,57)
        $studentRoster = "({$role} = 'student' AND EXISTS (SELECT 1 FROM enrolments e WHERE e.programme_id = assessments.programme_id AND e.student_id::text = {$actor}))";
        $guardianRoster = "({$role} = 'guardian' AND EXISTS (SELECT 1 FROM enrolments e JOIN guardian_links gl ON gl.student_id = e.student_id
            WHERE e.programme_id = assessments.programme_id AND gl.guardian_id::text = {$actor} AND gl.status = 'active'))";
        $schedExisting = "{$system} OR {$opsAudit} OR {$studentRoster} OR {$guardianRoster}";

        // assessment_results_read existing arms (create_assessments.php:68-71) — $released defined ONCE
        $released = "EXISTS (SELECT 1 FROM assessments a WHERE a.id = assessment_results.assessment_id AND a.status = 'released')";
        $ownReleased = "(student_id::text = {$actor} AND {$released})";
        $guardianReleased = "(EXISTS (SELECT 1 FROM guardian_links gl WHERE gl.student_id = assessment_results.student_id AND gl.guardian_id::text = {$actor} AND gl.status = 'active') AND {$released})";
        $resultsExisting = "{$system} OR {$opsAudit} OR {$ownReleased} OR {$guardianReleased}";

        return [$schedExisting, $resultsExisting, $released, $role, $caps, $schools];
    }

    /** A-3 capabilitiesForProgramme precedence in SQL, keyed on a DIRECT programme-id column (schedule). */
    private function heldFor(string $cap, string $programmeCol, string $schools): string
    {
        return "(
            EXISTS (SELECT 1 FROM programme_authority_overrides o
                    WHERE o.programme_id = {$programmeCol} AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}) AND o.mode = 'grant')
            OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o
                    WHERE o.programme_id = {$programmeCol} AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}))
                AND (EXISTS (SELECT 1 FROM programme_authority_overrides o
                        WHERE o.programme_id = {$programmeCol} AND o.capability = '{$cap}'
                          AND o.school_id IS NULL AND o.mode = 'grant')
                     OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o
                            WHERE o.programme_id = {$programmeCol} AND o.capability = '{$cap}'
                              AND o.school_id IS NULL)
                         AND EXISTS (SELECT 1 FROM school_authority_grants g
                            WHERE g.school_id::text = ANY({$schools}) AND g.capability = '{$cap}'
                              AND g.revoked_at IS NULL))))
        )";
    }

    /** Same precedence, but the RESULT's programme is reached via JOIN assessments (results has no programme_id). */
    private function heldForResults(string $cap, string $schools): string
    {
        $join = 'JOIN assessments a ON a.id = assessment_results.assessment_id';

        return "(
            EXISTS (SELECT 1 FROM programme_authority_overrides o {$join}
                    WHERE o.programme_id = a.programme_id AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}) AND o.mode = 'grant')
            OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o {$join}
                    WHERE o.programme_id = a.programme_id AND o.capability = '{$cap}'
                      AND o.school_id::text = ANY({$schools}))
                AND (EXISTS (SELECT 1 FROM programme_authority_overrides o {$join}
                        WHERE o.programme_id = a.programme_id AND o.capability = '{$cap}'
                          AND o.school_id IS NULL AND o.mode = 'grant')
                     OR (NOT EXISTS (SELECT 1 FROM programme_authority_overrides o {$join}
                            WHERE o.programme_id = a.programme_id AND o.capability = '{$cap}'
                              AND o.school_id IS NULL)
                         AND EXISTS (SELECT 1 FROM school_authority_grants g
                            WHERE g.school_id::text = ANY({$schools}) AND g.capability = '{$cap}'
                              AND g.revoked_at IS NULL))))
        )";
    }
};
