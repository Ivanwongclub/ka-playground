<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04D STEP 3 (OD-30) — school vouching is the model's ONE single-actor path, and
 * only over a school's OWN roll. Every vouched (school_mediated) guardian link's
 * student must be on a school roll (an active school_link). A vouched link for a
 * student on no roll would be a single actor attaching a guardian to a child they
 * have no authority over — the exact abuse OD-30's roll limit prevents.
 */
class VouchScopeAssertion implements Assertion
{
    public function key(): string
    {
        return 'links.vouch_scope';
    }

    public function proves(): string
    {
        return 'every vouched (school_mediated) guardian link\'s student is on a school roll — the OD-30 single-actor path never reaches beyond a school\'s own students';
    }

    public function cites(): string
    {
        return 'OD-30 · S04D STEP 3';
    }

    public function tags(): array
    {
        return ['S04D'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            "SELECT l.id FROM guardian_links l
             WHERE l.status = 'active' AND l.origin = 'school_mediated'
               AND NOT EXISTS (
                   SELECT 1 FROM school_links sl WHERE sl.student_id = l.student_id AND sl.status = 'active'
               )"
        );

        if ($bad !== []) {
            return AssertionResult::fail(count($bad).' vouched link(s) whose student is on no school roll — a vouch beyond the school\'s own students (OD-30)');
        }

        return AssertionResult::pass('every vouched link\'s student is on a school roll');
    }
}
