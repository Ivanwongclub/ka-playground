<?php

namespace App\Services\Identity;

/** Null adapter — no enrolment module exists before S04A. */
class NoEnrolmentsYet implements EnrolmentStatusPort
{
    public function hasNonTerminalEnrolments(int $studentId): bool
    {
        return false;
    }
}
