<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04F STEP 2 (OD-25 · the S04E hand-off) — every consolidated invoice's
 * `original_amount_minor` equals the sum of the orders it covers. No invoiced
 * amount exists without a matching covered order. Together with `invoices.balance`
 * (balance = original − Σ credit_notes, OD-54) this closes the chain
 * order_line → order → invoice original → invoice balance.
 */
class InvoiceLineReconciliationAssertion implements Assertion
{
    public function key(): string
    {
        return 'invoices.line_reconciliation';
    }

    public function proves(): string
    {
        return 'every consolidated invoice original equals the sum of its covered orders (no invoiced amount without a matching covered order); with invoices.balance the order→invoice chain reconciles end to end';
    }

    public function cites(): string
    {
        return 'OD-25 · OD-18 · S04F STEP 2';
    }

    public function tags(): array
    {
        return ['S04F'];
    }

    public function check(): AssertionResult
    {
        $mismatched = DB::select(
            "SELECT ci.id,
                    ci.original_amount_minor AS original,
                    COALESCE(SUM(o.total_amount_minor), 0) AS covered
             FROM consolidated_invoices ci
             LEFT JOIN orders o
                    ON o.consolidated_invoice_id = ci.id AND o.status = 'covered_by_invoice'
             GROUP BY ci.id, ci.original_amount_minor
             HAVING ci.original_amount_minor <> COALESCE(SUM(o.total_amount_minor), 0)"
        );

        if ($mismatched !== []) {
            $r = $mismatched[0];

            return AssertionResult::fail(
                count($mismatched)." consolidated invoice(s) whose original does not equal Σ covered orders (e.g. original {$r->original} vs covered {$r->covered})"
            );
        }

        return AssertionResult::pass('every consolidated invoice original equals the sum of its covered orders');
    }
}
