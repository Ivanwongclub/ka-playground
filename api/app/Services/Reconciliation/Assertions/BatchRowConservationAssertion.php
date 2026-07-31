<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04E STEP 2 (Spec Part H P4 · OD-31). Every row of a COMMITTED batch lands in
 * exactly one terminal bucket — Enrolled / NotEnrolled / Skipped / Failed — and
 * every non-Enrolled row carries a reason. No row is undispositioned, none is
 * silently dropped, and there is NO "Waiting" bucket (enrolment is intent, not a
 * seat — there is no capacity/waitlist at batch time).
 */
class BatchRowConservationAssertion implements Assertion
{
    private const TERMINAL = ['enrolled', 'not_enrolled', 'skipped', 'failed'];

    public function key(): string
    {
        return 'batches.row_conservation';
    }

    public function proves(): string
    {
        return 'every committed batch row is in exactly one terminal outcome (enrolled/not_enrolled/skipped/failed), each non-enrolled carrying a reason — no undispositioned or unreasoned row, and no "waiting" (enrolment is intent, OD-31)';
    }

    public function cites(): string
    {
        return 'Spec Part H P4 · OD-31 · S04E STEP 2';
    }

    public function tags(): array
    {
        return ['S04E'];
    }

    public function check(): AssertionResult
    {
        $committed = ['complete', 'partially_complete'];
        $terminal = "('".implode("','", self::TERMINAL)."')";

        // (a) a committed batch with a row not in a terminal bucket
        $undispositioned = DB::select(
            "SELECT r.id FROM enrolment_batch_rows r
             JOIN enrolment_batches b ON b.id = r.batch_id
             WHERE b.status IN ('".implode("','", $committed)."')
               AND r.status NOT IN {$terminal}"
        );
        if ($undispositioned !== []) {
            return AssertionResult::fail(
                count($undispositioned).' row(s) in a committed batch have no terminal outcome — an undispositioned row'
            );
        }

        // (b) a non-enrolled terminal row with no reason
        $unreasoned = DB::select(
            "SELECT r.id FROM enrolment_batch_rows r
             JOIN enrolment_batches b ON b.id = r.batch_id
             WHERE b.status IN ('".implode("','", $committed)."')
               AND r.status IN ('not_enrolled','skipped','failed')
               AND (r.reason IS NULL OR r.reason = '')"
        );
        if ($unreasoned !== []) {
            return AssertionResult::fail(
                count($unreasoned).' non-enrolled row(s) carry no reason — a silently-dropped outcome (P4)'
            );
        }

        return AssertionResult::pass('every committed batch row has a terminal outcome, each non-enrolled reasoned (no waiting)');
    }
}
