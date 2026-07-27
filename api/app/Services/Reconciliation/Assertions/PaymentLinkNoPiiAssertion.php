<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** OD-44: the anonymous payload can never identify a child — initials only, structurally. */
class PaymentLinkNoPiiAssertion implements Assertion
{
    public function key(): string
    {
        return 'payment_links.no_pii';
    }

    public function proves(): string
    {
        return 'no payment_links row carries more than initials — checked against the stored frozen payload, and the table has no name/email/plaintext-token columns at all';
    }

    public function cites(): string
    {
        return 'OD-44';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        // structural: forbidden columns must not exist (incl. plaintext token)
        $columns = array_map(fn ($c) => $c->column_name, DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'payment_links'"));
        $forbidden = array_intersect($columns, ['token', 'student_name', 'name', 'email', 'phone']);
        if ($forbidden !== []) {
            return AssertionResult::fail('forbidden column(s) on payment_links: '.implode(', ', $forbidden));
        }
        // row-level: the frozen initials must LOOK like initials, never a name
        $bad = DB::select("SELECT id, student_initials FROM payment_links
            WHERE length(student_initials) > 12 OR student_initials ~ '\\s' OR student_initials ~ '[a-z]{2,}'");
        if ($bad !== []) {
            return AssertionResult::fail(count($bad).' link row(s) whose stored payload is more than initials: '.implode(', ', array_map(fn ($r) => $r->id, array_slice($bad, 0, 5))));
        }
        $total = (int) DB::table('payment_links')->count();

        return AssertionResult::pass("{$total} link row(s) checked".($total === 0 ? ' (vacuous)' : ', initials-only holds').'; no forbidden columns');
    }
}
