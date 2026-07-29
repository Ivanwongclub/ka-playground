<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04C STEP 3 (D-iv · FLAG #2). The structural proof behind FLAG #2: S06's
 * requires_all consent hardening reads "active-as-of-confirm" from guardian_link
 * audit events with to_state='active'. A link that reaches 'active' WITHOUT that
 * audit would read as not-active there — a SILENT false green on a child's
 * consent. This assertion makes that failure loud: every active guardian_link
 * must carry its to_state='active' audit, whatever path activated it.
 */
class LinkActivationAuditedAssertion implements Assertion
{
    public function key(): string
    {
        return 'links.activation_audited';
    }

    public function proves(): string
    {
        return 'every active guardian_link carries a to_state=\'active\' audit event — so FLAG #2 (which S06 requires_all consent hardening depends on) is a proof, not a promise; a path that activates without auditing fails loudly here instead of silently there';
    }

    public function cites(): string
    {
        return 'FLAG #2 · S06 AUDIT §8 · D-iv · OD-23';
    }

    /** Tagged S06 too: it guards an S06 dependency, so --tag=S06 runs it. */
    public function tags(): array
    {
        return ['S04C', 'S06'];
    }

    public function check(): AssertionResult
    {
        $orphans = DB::select(
            "SELECT gl.id FROM guardian_links gl
             WHERE gl.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM audit_events ae
                   WHERE ae.entity_type = 'guardian_link'
                     AND ae.entity_id = gl.id::text
                     AND ae.to_state = 'active'
               )"
        );

        if ($orphans !== []) {
            $ids = implode(', ', array_map(fn ($r) => $r->id, array_slice($orphans, 0, 5)));

            return AssertionResult::fail(
                count($orphans)." active guardian_link(s) have NO to_state='active' audit event — FLAG #2 would read them as not-active in S06 consent hardening (ids: {$ids})"
            );
        }

        return AssertionResult::pass('every active guardian_link carries its to_state=\'active\' activation audit');
    }
}
