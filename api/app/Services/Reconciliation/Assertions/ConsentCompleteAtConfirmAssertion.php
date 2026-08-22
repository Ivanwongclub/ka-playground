<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 (OD-57/58) — no team was Team Formation-confirmed with a member whose consent was
 * unsatisfied OR STALE at that moment. Judged by PAST FACTS: for every
 * `enrolment.confirmed` audit event (the confirm-time fact), the student had a
 * consent SIGNATURE that was both (a) signed at or before that event AND (b) whose
 * request was NOT already superseded by then — a `consent_request.superseded`
 * audit event dated at or before the confirm makes the signature STALE. A material
 * change superseding the consent LATER (occurred_at > confirm) does NOT red a
 * correctly-confirmed team; a supersede BEFORE confirm does. Supersede time comes
 * from the immutable supersede audit event, not a mutable column. Scope: at least
 * one satisfied, non-stale signature by confirm time; the requires_all_guardians
 * nuance is enforced live at Team Formation (consentSatisfied under the FOR SHARE lock).
 */
class ConsentCompleteAtConfirmAssertion implements Assertion
{
    public function key(): string
    {
        return 'teams.consent_complete_at_confirm';
    }

    public function proves(): string
    {
        return 'every Formation-confirmed enrolment had a consent signature signed at or before its confirm event AND not superseded by then — no team confirmed on unsatisfied OR stale consent (judged by confirm-time facts, not live state)';
    }

    public function cites(): string
    {
        return 'OD-57 · OD-58 · BI-6';
    }

    public function tags(): array
    {
        return ['S05', 'S06']; // S06-6 hardened it with the requires_all-guardians at-rest branch
    }

    public function check(): AssertionResult
    {
        // (1) the ≥1 case — EVERY confirmed enrolment had at least one non-stale signature by confirm.
        $bad = DB::select(
            "SELECT ae.event_id
             FROM audit_events ae
             JOIN enrolments e ON e.id::text = ae.entity_id
             WHERE ae.entity_type = 'enrolment' AND ae.action = 'enrolment.confirmed'
               AND NOT EXISTS (".self::NON_STALE_SIGNATURE.")"
        );

        // (2) S06-6 hardening — for requires_all_guardians programmes, EVERY guardian ACTIVE AS OF confirm
        // (judged by the immutable guardian_link lifecycle audits: a to_state='active' event by T, and no
        // 'guardian_link.revoked' by T) must ALSO have their own non-stale signature by confirm.
        $badAll = DB::select(
            "SELECT ae.event_id
             FROM audit_events ae
             JOIN enrolments e ON e.id::text = ae.entity_id
             JOIN wizard_sections ws ON ws.programme_id = e.programme_id AND ws.section_key = 'consent'
             WHERE ae.entity_type = 'enrolment' AND ae.action = 'enrolment.confirmed'
               AND COALESCE((ws.data::jsonb->>'requires_all_guardians')::boolean, false) = true
               AND EXISTS (
                 SELECT 1 FROM guardian_links gl
                 WHERE gl.student_id = e.student_id
                   -- active AS OF confirm: activated by T, not revoked by T (immutable link audits)
                   AND EXISTS (SELECT 1 FROM audit_events la WHERE la.entity_type = 'guardian_link' AND la.entity_id = gl.id::text AND la.to_state = 'active' AND la.occurred_at <= ae.occurred_at)
                   AND NOT EXISTS (SELECT 1 FROM audit_events lr WHERE lr.entity_type = 'guardian_link' AND lr.entity_id = gl.id::text AND lr.action = 'guardian_link.revoked' AND lr.occurred_at <= ae.occurred_at)
                   -- …but this active guardian has NO non-stale signature of their own by T
                   AND NOT EXISTS (".self::NON_STALE_SIGNATURE." AND cr.signer_id = gl.guardian_id)
               )"
        );

        $violations = array_unique(array_merge(array_column($bad, 'event_id'), array_column($badAll, 'event_id')));
        $total = (int) DB::table('audit_events')->where('entity_type', 'enrolment')->where('action', 'enrolment.confirmed')->count();
        $count = count($violations);

        return $count > 0
            ? AssertionResult::fail("{$count} Formation-confirmed enrolment(s) confirmed on unsatisfied/stale consent — a required guardian's consent signature missing or superseded by confirm (OD-57/58; requires_all hardened)")
            : AssertionResult::pass("{$total} Formation confirm event(s) checked".($total === 0 ? ' (vacuous)' : ', all had complete non-stale consent at confirm (incl. requires_all)'));
    }

    /** A signed, not-superseded-before-confirm signature for (e.programme_id, e.student_id), correlated to ae.occurred_at. */
    private const NON_STALE_SIGNATURE = "SELECT 1 FROM consent_signatures cs
        JOIN consent_requests cr ON cr.id = cs.request_id
        WHERE cr.programme_id = e.programme_id AND cr.student_id = e.student_id
          AND cs.signed_at <= ae.occurred_at
          AND NOT EXISTS (
            SELECT 1 FROM audit_events sup
            WHERE sup.entity_type = 'consent_request' AND sup.entity_id = cr.id::text
              AND sup.action = 'consent_request.superseded' AND sup.occurred_at <= ae.occurred_at
          )";
}
