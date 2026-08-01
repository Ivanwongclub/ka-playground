<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04F STEP 1 (OD-25 · D-18) — the payer of every obligation and every order is
 * consistent with its programme's E6 `payer_party`, via the ONE mapping
 * (parent→guardian, student→student, school→school). A programme-`school`
 * enrolment that carries a non-`school` payer (or vice-versa) means the E6 wire
 * was bypassed or mismapped — the exact failure that would silently drop a
 * school order from the invoice branch. This reds on any such mismatch.
 */
class ObligationPayerMatchesProgrammeAssertion implements Assertion
{
    public function key(): string
    {
        return 'obligations.payer_matches_programme';
    }

    public function proves(): string
    {
        return "every payment_obligation and order carries the payer its programme's E6 payer_party maps to (parent→guardian, student→student, school→school) — no school programme silently keeps a guardian payer and drops from the invoice branch";
    }

    public function cites(): string
    {
        return 'OD-25 · D-18 · S04F STEP 1';
    }

    public function tags(): array
    {
        return ['S04F'];
    }

    public function check(): AssertionResult
    {
        // The canonical mapping, as SQL. A programme E6 value outside the mapped
        // set yields NULL → treated as a mismatch (IS DISTINCT FROM catches it).
        $expected = "CASE p.payer_party WHEN 'parent' THEN 'guardian' WHEN 'student' THEN 'student' WHEN 'school' THEN 'school' END";

        $badObligations = DB::select(
            "SELECT o.id FROM payment_obligations o JOIN programmes p ON p.id = o.programme_id
             WHERE o.payer_party IS DISTINCT FROM ({$expected})"
        );
        if ($badObligations !== []) {
            return AssertionResult::fail(
                count($badObligations)." payment_obligation(s) carry a payer that does not match their programme's E6 payer_party (D-18)"
            );
        }

        $badOrders = DB::select(
            "SELECT o.id FROM orders o JOIN programmes p ON p.id = o.programme_id
             WHERE o.payer_party IS DISTINCT FROM ({$expected})"
        );
        if ($badOrders !== []) {
            return AssertionResult::fail(
                count($badOrders)." order(s) carry a payer that does not match their programme's E6 payer_party (D-18)"
            );
        }

        return AssertionResult::pass("every obligation and order payer matches its programme's E6 payer_party");
    }
}
