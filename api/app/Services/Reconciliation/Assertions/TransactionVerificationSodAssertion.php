<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 2 (FR061 · D-16) — the team-finance segregation of duty: no
 * transaction was verified by the person who recorded it. The tt_sod_check CHECK
 * constraint makes this impossible at write time (system and superuser alike);
 * this is the path-independent reconciliation belt — the analog of
 * payments.bi9_manual_sod for team-project money, on a NEW mechanism (nothing
 * re-homed).
 */
class TransactionVerificationSodAssertion implements Assertion
{
    public function key(): string
    {
        return 'finance.verification_sod';
    }

    public function proves(): string
    {
        return 'no team transaction was verified by its own recorder (verified_by ≠ recorded_by) — the recorder can never confirm their own spend, whatever the write path';
    }

    public function cites(): string
    {
        return 'FR061 · D-16 · S07 STEP 2';
    }

    public function tags(): array
    {
        return ['S07'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            'SELECT id FROM team_transactions WHERE verified_by IS NOT NULL AND verified_by = recorded_by'
        );

        if ($bad !== []) {
            return AssertionResult::fail(
                count($bad).' transaction(s) verified by their own recorder — a segregation-of-duty breach (D-16)'
            );
        }

        return AssertionResult::pass('no transaction was verified by its own recorder (SoD holds)');
    }
}
