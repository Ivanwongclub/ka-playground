<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** 2.8/BI-4/OD-63: the partial unique index enforcing one live enrolment per student × programme exists and is enforced. */
class EnrolmentUniquenessProbe implements Assertion
{
    public function key(): string
    {
        return 'enrolments.one_per_student_programme';
    }

    public function proves(): string
    {
        return 'one live enrolment per student × programme is enforced by a partial unique index at the database';
    }

    public function cites(): string
    {
        return '2.8 · BI-4 · OD-63';
    }

    public function tags(): array
    {
        return ['S04A'];
    }

    public function check(): AssertionResult
    {
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'enrolments' AND indexname = 'enrolments_one_live'");
        if ($index === null || ! str_contains($index->indexdef, 'UNIQUE')
            || ! str_contains($index->indexdef, 'student_id') || ! str_contains($index->indexdef, 'programme_id')) {
            return AssertionResult::fail('partial unique index enrolments_one_live missing or malformed');
        }
        $dupes = DB::selectOne("SELECT count(*) AS c FROM (SELECT student_id, programme_id FROM enrolments WHERE status NOT IN ('completed','withdrawn','released') GROUP BY 1,2 HAVING count(*) > 1) d");

        return ((int) $dupes->c) > 0
            ? AssertionResult::fail("{$dupes->c} duplicate live enrolment pair(s)")
            : AssertionResult::pass('index present and no duplicate live pairs');
    }
}
