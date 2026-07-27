<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** BI-7/BI-8/OD-64: every enrolment status is backed by its audited transition; Withdrawn only via an approved request. */
class EnrolmentStatusBypassAssertion implements Assertion
{
    public function key(): string
    {
        return 'enrolments.no_status_bypass';
    }

    public function proves(): string
    {
        return 'every enrolment status has its audited transition with a non-null actor attribution, and every Withdrawn enrolment traces to an approved withdrawal request';
    }

    public function cites(): string
    {
        return 'BI-7 · BI-8 · OD-64';
    }

    public function tags(): array
    {
        return ['S04A'];
    }

    public function check(): AssertionResult
    {
        $unaudited = DB::selectOne("SELECT count(*) AS c FROM enrolments e WHERE NOT EXISTS (
            SELECT 1 FROM audit_events a WHERE a.entity_type = 'enrolment' AND a.entity_id = e.id::text
              AND a.to_state = e.status AND (a.actor_id IS NOT NULL OR a.actor_role IS NOT NULL))");
        $orphanWithdrawn = DB::selectOne("SELECT count(*) AS c FROM enrolments e WHERE e.status = 'withdrawn'
            AND NOT EXISTS (SELECT 1 FROM withdrawal_requests wr WHERE wr.enrolment_id = e.id AND wr.status = 'approved')");
        $failures = [];
        if ((int) $unaudited->c > 0) {
            $failures[] = "{$unaudited->c} enrolment(s) whose current status has no attributed audit event";
        }
        if ((int) $orphanWithdrawn->c > 0) {
            $failures[] = "{$orphanWithdrawn->c} Withdrawn enrolment(s) without an approved withdrawal request";
        }

        $total = (int) DB::table('enrolments')->count();

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures))
            : AssertionResult::pass("{$total} enrolment(s) checked".($total === 0 ? ' (vacuous)' : ', every status audited and attributed'));
    }
}
