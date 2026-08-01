<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04F STEP 3 (OD-55) — the school-settled analog of deadlines.no_silent_lapse:
 * no `issued` school invoice past due_at + grace is left un-aged. Every overdue
 * receivable carries the `overdue` status + its audit — the academy exception is
 * never silent, and the aging sweep must keep up. (Unlike the family-paid lapse,
 * NO child is suspended — the invoice ages, the enrolment does not.)
 */
class NoSilentOverdueAssertion implements Assertion
{
    public function key(): string
    {
        return 'invoices.no_silent_overdue';
    }

    public function proves(): string
    {
        return 'no issued school-settled invoice past its due_at + grace is left un-aged — every overdue receivable is flagged overdue with an audit, so the academy collections exception is never silent (the invoice ages, never a child)';
    }

    public function cites(): string
    {
        return 'OD-55 · S04F STEP 3';
    }

    public function tags(): array
    {
        return ['S04F'];
    }

    public function check(): AssertionResult
    {
        $grace = (int) config('finance.school_invoice_grace_days', 7);

        $scrutinised = DB::table('consolidated_invoices')
            ->where('status', 'issued')
            ->whereNotNull('due_at')
            ->whereRaw("due_at + (? || ' days')::interval < now()", [$grace]);

        $silent = (clone $scrutinised)->count();

        if ($silent > 0) {
            return AssertionResult::fail(
                "{$silent} school invoice(s) past due_at + {$grace}d grace still 'issued', not aged to overdue — run invoices:age-school-settled"
            );
        }

        return AssertionResult::pass('every school invoice past due_at + grace is aged to overdue (no silent receivable)');
    }
}
