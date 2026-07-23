<?php

namespace App\Services\Identity;

/**
 * Port for the 2.2 continuity condition. Enrolments arrive in S04A; until then
 * the null adapter reports none, which makes the sole-guardian guard vacuously
 * permissive — exactly as the S01 card states ("vacuous until S04A"). S04A
 * replaces the binding with the real enrolment query.
 */
interface EnrolmentStatusPort
{
    public function hasNonTerminalEnrolments(int $studentId): bool;
}
