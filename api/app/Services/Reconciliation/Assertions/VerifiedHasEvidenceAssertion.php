<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 2 (FR061 · spec:43) — every Verified team transaction has a
 * scan-clean evidence upload. The state ordering + the tt_verified_has_evidence
 * CHECK make a verified-without-evidence row impossible at write time; this is
 * the reconciliation belt, and it additionally requires the evidence to be
 * scan-CLEAN (BI-10), path-independent.
 */
class VerifiedHasEvidenceAssertion implements Assertion
{
    public function key(): string
    {
        return 'finance.verified_has_evidence';
    }

    public function proves(): string
    {
        return 'every Verified team transaction carries a scan-clean evidence upload — no entry is verified against offline reality without the receipt to back it';
    }

    public function cites(): string
    {
        return 'FR061 · BI-10 · S07 STEP 2';
    }

    public function tags(): array
    {
        return ['S07'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            "SELECT t.id FROM team_transactions t
             LEFT JOIN uploads u ON u.id = t.evidence_upload_id
             WHERE t.status = 'verified' AND (u.id IS NULL OR u.status <> 'clean')"
        );

        if ($bad !== []) {
            return AssertionResult::fail(
                count($bad).' verified transaction(s) with no scan-clean evidence — a verified entry without a backing receipt'
            );
        }

        return AssertionResult::pass('every verified transaction has scan-clean evidence attached');
    }
}
