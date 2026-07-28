<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 (OD-57/58) — no team was 成團-confirmed with a member whose consent was
 * unsatisfied OR STALE at that moment. Judged by PAST FACTS: for every
 * `enrolment.confirmed` audit event (the confirm-time fact), the student had a
 * consent SIGNATURE that was both (a) signed at or before that event AND (b) whose
 * request was NOT already superseded by then — a `consent_request.superseded`
 * audit event dated at or before the confirm makes the signature STALE. A material
 * change superseding the consent LATER (occurred_at > confirm) does NOT red a
 * correctly-confirmed team; a supersede BEFORE confirm does. Supersede time comes
 * from the immutable supersede audit event, not a mutable column. Scope: at least
 * one satisfied, non-stale signature by confirm time; the requires_all_guardians
 * nuance is enforced live at 成團 (consentSatisfied under the FOR SHARE lock).
 */
class ConsentCompleteAtConfirmAssertion implements Assertion
{
    public function key(): string
    {
        return 'teams.consent_complete_at_confirm';
    }

    public function proves(): string
    {
        return 'every 成團-confirmed enrolment had a consent signature signed at or before its confirm event AND not superseded by then — no team confirmed on unsatisfied OR stale consent (judged by confirm-time facts, not live state)';
    }

    public function cites(): string
    {
        return 'OD-57 · OD-58 · BI-6';
    }

    public function tags(): array
    {
        return ['S05'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            "SELECT ae.event_id
             FROM audit_events ae
             JOIN enrolments e ON e.id::text = ae.entity_id
             WHERE ae.entity_type = 'enrolment' AND ae.action = 'enrolment.confirmed'
               AND NOT EXISTS (
                 SELECT 1 FROM consent_signatures cs
                 JOIN consent_requests cr ON cr.id = cs.request_id
                 WHERE cr.programme_id = e.programme_id AND cr.student_id = e.student_id
                   AND cs.signed_at <= ae.occurred_at
                   -- and the request was NOT already superseded by confirm time (else the signature was STALE)
                   AND NOT EXISTS (
                     SELECT 1 FROM audit_events sup
                     WHERE sup.entity_type = 'consent_request' AND sup.entity_id = cr.id::text
                       AND sup.action = 'consent_request.superseded' AND sup.occurred_at <= ae.occurred_at
                   )
               )"
        );
        $total = (int) DB::table('audit_events')->where('entity_type', 'enrolment')->where('action', 'enrolment.confirmed')->count();
        $count = count($bad);

        return $count > 0
            ? AssertionResult::fail("{$count} 成團-confirmed enrolment(s) with NO consent signature by confirm time — confirmed on unsatisfied/stale consent (OD-57/58)")
            : AssertionResult::pass("{$total} 成團 confirm event(s) checked".($total === 0 ? ' (vacuous)' : ', all had consent at confirm time'));
    }
}
