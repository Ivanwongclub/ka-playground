<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S05-3 guardrail 2 (Leo ruling 2026-07-27) — the provenance guarantee for the
 * one refund path that has NO two-person control. A backstop_auto refund is
 * created by the SYSTEM, out of BI-9 (OD-47); the replacement control is this
 * assertion: every such refund MUST trace to a genuinely parked enrolment — a
 * parked_rollforward exception whose backstop_at had already passed at the
 * refund's creation time. No lapsed-parking cause ⇒ no auto-refund may exist.
 *
 * This is the auto-refund analogue of "every Withdrawn enrolment traces to an
 * approved withdrawal": confinement is a fact about today's code; this is the
 * runtime guarantee that survives future callers, bugs, or bad data.
 */
class RefundBackstopProvenanceAssertion implements Assertion
{
    public function key(): string
    {
        return 'refunds.backstop_provenance';
    }

    public function proves(): string
    {
        return "every origin='backstop_auto' refund traces to a genuinely parked enrolment (a parked_rollforward exception whose backstop_at had passed at refund time) — no auto-refund exists without a real lapsed-parking cause";
    }

    public function cites(): string
    {
        return 'OD-35 · OD-47 · OD-48';
    }

    public function tags(): array
    {
        return ['S05'];
    }

    public function check(): AssertionResult
    {
        $orphans = DB::table('refunds as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.origin', 'backstop_auto')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw('1'))->from('team_exceptions as te')
                    ->whereColumn('te.enrolment_id', 'o.enrolment_id')
                    ->where('te.type', 'parked_rollforward')
                    ->whereNotNull('te.backstop_at')
                    ->whereColumn('te.backstop_at', '<=', 'r.created_at');
            })
            ->count();

        $total = (int) DB::table('refunds')->where('origin', 'backstop_auto')->count();

        return $orphans > 0
            ? AssertionResult::fail("{$orphans} backstop_auto refund(s) with NO lapsed parked_rollforward cause — an out-of-BI-9 auto-refund without provenance (OD-35/47)")
            : AssertionResult::pass("{$total} backstop_auto refund(s) checked".($total === 0 ? ' (vacuous)' : ', all trace to a lapsed parking'));
    }
}
