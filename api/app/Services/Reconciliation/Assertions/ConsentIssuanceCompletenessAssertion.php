<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * The lost-job hole closed structurally (S03 gate lesson): every pre-team
 * enrolment has a consent request addressed to EVERY active guardian of its
 * student. A dropped issuance job goes red here nightly.
 */
class ConsentIssuanceCompletenessAssertion implements Assertion
{
    public function key(): string
    {
        return 'consent.issuance_completeness';
    }

    public function proves(): string
    {
        return 'every pre-team enrolment has a consent request addressed to every active guardian of its student';
    }

    public function cites(): string
    {
        return 'S04A design answer · OD-10';
    }

    public function tags(): array
    {
        return ['S04A'];
    }

    public function check(): AssertionResult
    {
        $missing = DB::select("
            SELECT e.id FROM enrolments e
            JOIN guardian_links gl ON gl.student_id = e.student_id AND gl.status = 'active'
            WHERE e.status IN ('pending_consent','in_pool') AND e.updated_at < now() - interval '10 minutes'
              AND NOT EXISTS (
                SELECT 1 FROM consent_requests cr
                WHERE cr.programme_id = e.programme_id AND cr.student_id = e.student_id
                  AND cr.signer_id = gl.guardian_id AND cr.status IN ('sent','viewed','signed'))
            GROUP BY e.id");
        $checked = (int) DB::table('enrolments')->whereIn('status', ['pending_consent', 'in_pool'])->count();

        return $missing !== []
            ? AssertionResult::fail(count($missing).' enrolment(s) with a guardian lacking any open/signed request: '.implode(', ', array_slice(array_column($missing, 'id'), 0, 5)))
            : AssertionResult::pass("{$checked} pre-team enrolment(s) checked".($checked === 0 ? ' (vacuous)' : ', issuance complete per active guardian'));
    }
}
