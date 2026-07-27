<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * The Q1 atomicity gap, closed by structure and checked nightly: no Confirmed
 * enrolment outlives the issuance window without its money artifact, and no
 * obligation sits unconsumed past it (a dead consumer goes red here).
 */
class PaymentObligationCompletenessAssertion implements Assertion
{
    public function key(): string
    {
        return 'payment_obligations.completeness';
    }

    public function proves(): string
    {
        return 'every settled Confirmed enrolment has an obligation and its order; no obligation sits unconsumed past the issuance window';
    }

    public function cites(): string
    {
        return 'OD-43 · Q1 verdict (2026-07-27)';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        $window = now()->subMinutes(10);
        $noObligation = DB::selectOne("SELECT count(*) AS c FROM enrolments e
            WHERE e.status IN ('confirmed','active') AND e.updated_at < ?
              AND NOT EXISTS (SELECT 1 FROM payment_obligations po WHERE po.enrolment_id = e.id)
              AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.enrolment_id = e.id AND o.status <> 'cancelled')",
            [$window]);
        $staleUnconsumed = DB::selectOne('SELECT count(*) AS c FROM payment_obligations WHERE consumed_at IS NULL AND created_at < ?', [$window]);
        $consumedWithoutOrder = DB::selectOne('SELECT count(*) AS c FROM payment_obligations WHERE consumed_at IS NOT NULL AND order_id IS NULL');

        $failures = [];
        if ((int) $noObligation->c > 0) {
            $failures[] = "{$noObligation->c} Confirmed enrolment(s) with NO obligation and no order (the atomicity break)";
        }
        if ((int) $staleUnconsumed->c > 0) {
            $failures[] = "{$staleUnconsumed->c} obligation(s) unconsumed past the window (dead consumer)";
        }
        if ((int) $consumedWithoutOrder->c > 0) {
            $failures[] = "{$consumedWithoutOrder->c} consumed obligation(s) without an order id";
        }
        $total = (int) DB::table('payment_obligations')->count();

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures))
            : AssertionResult::pass("{$total} obligation(s) checked".($total === 0 ? ' (vacuous until 成團 volume)' : ', all consumed into orders within the window'));
    }
}
