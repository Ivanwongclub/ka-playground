<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04C STEP 3 (OD-23, Leo 1a — the typo guard). A relationship claimed on a
 * registration form against a not-yet-registered address is HELD, not linked. It
 * may only MATERIALISE into a pending link once that address proves control of
 * itself (verification) — otherwise a typo'd address later registered by an
 * unrelated stranger would surface a stranger as a pending relationship.
 *
 * Exact predicate: every held_link in status 'materialised' must correspond to a
 * counterpart account that IS verified (email_verified_at not null). A
 * materialisation with no verified counterpart account is the failure this guards
 * — a held/unverified claim that became a pending link without the proper path.
 */
class NoUnverifiedMaterialisationAssertion implements Assertion
{
    public function key(): string
    {
        return 'links.no_unverified_materialisation';
    }

    public function proves(): string
    {
        return 'no held link materialised into a pending link against an address that was not verified — a form-claim becomes a pending relationship only once the counterpart proves control of its own address';
    }

    public function cites(): string
    {
        return 'OD-23 · Leo 1a · S04C STEP 3';
    }

    public function tags(): array
    {
        return ['S04C'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            "SELECT hl.id, hl.counterpart_email FROM held_links hl
             WHERE hl.status = 'materialised'
               AND NOT EXISTS (
                   SELECT 1 FROM users u
                   WHERE u.email = hl.counterpart_email
                     AND u.email_verified_at IS NOT NULL
               )"
        );

        if ($bad !== []) {
            return AssertionResult::fail(
                count($bad).' materialised held link(s) have no VERIFIED counterpart account — a form-claim became a pending link without the address being verified'
            );
        }

        return AssertionResult::pass('every materialised held link has a verified counterpart — no unverified materialisation');
    }
}
