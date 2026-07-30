<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04C STEP 4 (OD-23). Every account exists for a recorded reason — there is no
 * back door into being a user. An account traces to one of the audited origins:
 *   - a registration APPROVAL decision (user.created),
 *   - an accepted INVITATION (invitation_accepted naming the user),
 *   - the S00 BOOTSTRAP super-admin (bootstrap.super_admin),
 *   - (S04E) school BULK creation — additive when it lands, via user.created.
 * A user with none of these has no provenance and this reds. Synthetic seed data
 * carries the same provenance audit as the real paths, so the demo is honest too.
 */
class AccountProvenanceAssertion implements Assertion
{
    public function key(): string
    {
        return 'account.provenance';
    }

    public function proves(): string
    {
        return 'every account traces to an audited origin — a registration approval, an accepted invitation, or the bootstrap super-admin; no account exists without a recorded reason';
    }

    public function cites(): string
    {
        return 'OD-23 · OD-27 · S04C STEP 4';
    }

    public function tags(): array
    {
        return ['S04C'];
    }

    public function check(): AssertionResult
    {
        $orphans = DB::select(
            "SELECT u.id, u.email FROM users u
             WHERE NOT EXISTS (
                 SELECT 1 FROM audit_events ae
                 WHERE (ae.entity_type = 'user' AND ae.entity_id = u.id::text
                        AND ae.action IN ('user.created', 'bootstrap.super_admin'))
                    OR (ae.action = 'invitation_accepted' AND ae.payload_after->>'user_id' = u.id::text)
             )"
        );

        if ($orphans !== []) {
            $emails = implode(', ', array_map(fn ($r) => $r->email, array_slice($orphans, 0, 5)));

            return AssertionResult::fail(
                count($orphans).' account(s) have NO provenance audit — an account with no recorded origin ('.$emails.')'
            );
        }

        return AssertionResult::pass('every account traces to an audited origin (approval, invitation, or bootstrap)');
    }
}
