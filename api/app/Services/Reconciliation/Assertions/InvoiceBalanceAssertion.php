<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** OD-54: every consolidated invoice's balance = original − Σ its credit notes. */
class InvoiceBalanceAssertion implements Assertion
{
    public function key(): string
    {
        return 'invoices.balance';
    }

    public function proves(): string
    {
        return 'every consolidated invoice balance equals its original amount minus the sum of its credit notes (OD-54)';
    }

    public function cites(): string
    {
        return 'OD-54';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select("
            SELECT ci.id, ci.balance_minor, ci.original_amount_minor,
                   coalesce(sum(cn.amount_minor), 0) AS credited
            FROM consolidated_invoices ci
            LEFT JOIN credit_notes cn ON cn.consolidated_invoice_id = ci.id
            GROUP BY ci.id, ci.balance_minor, ci.original_amount_minor
            HAVING ci.balance_minor <> ci.original_amount_minor - coalesce(sum(cn.amount_minor), 0)");
        $total = (int) DB::table('consolidated_invoices')->count();

        return $bad !== []
            ? AssertionResult::fail(count($bad).' invoice(s) whose balance ≠ original − credits: '.implode(', ', array_map(fn ($r) => $r->id, array_slice($bad, 0, 5))))
            : AssertionResult::pass("{$total} consolidated invoice(s) checked".($total === 0 ? ' (vacuous)' : ', balance = original − credits holds'));
    }
}
