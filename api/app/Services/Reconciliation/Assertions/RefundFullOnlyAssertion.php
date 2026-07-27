<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** OD-48: every refund and credit note equals its order total — no partial money movements. */
class RefundFullOnlyAssertion implements Assertion
{
    public function key(): string
    {
        return 'refunds.full_only';
    }

    public function proves(): string
    {
        return 'every refund and every credit note equals its order total exactly (no pro-rata, OD-48)';
    }

    public function cites(): string
    {
        return 'OD-48';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        $badRefunds = DB::table('refunds as r')->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.status', '<>', 'rejected')
            ->whereColumn('r.amount_minor', '<>', 'o.total_amount_minor')->count();
        $badCredits = DB::table('credit_notes as cn')->join('orders as o', 'o.id', '=', 'cn.order_id')
            ->whereColumn('cn.amount_minor', '<>', 'o.total_amount_minor')->count();
        $failures = [];
        if ($badRefunds > 0) {
            $failures[] = "{$badRefunds} refund(s) ≠ order total";
        }
        if ($badCredits > 0) {
            $failures[] = "{$badCredits} credit note(s) ≠ order total";
        }
        $total = (int) DB::table('refunds')->count() + (int) DB::table('credit_notes')->count();

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures).' (OD-48: full-only)')
            : AssertionResult::pass("{$total} refund/credit-note row(s) checked".($total === 0 ? ' (vacuous)' : ', all full-amount'));
    }
}
