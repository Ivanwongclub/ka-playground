<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04E STEP 1 (BI-10). The path-independent backstop for the scan gate: a parsed
 * enrolment_batch_row may exist ONLY under an upload that passed the scan
 * (status = clean). A row under a non-clean or missing upload means a file was
 * parsed before it was proven clean — no matter how it got there — and reds.
 */
class BatchScanGatedAssertion implements Assertion
{
    public function key(): string
    {
        return 'batches.scan_gated';
    }

    public function proves(): string
    {
        return 'every parsed batch row sits under a scan-CLEAN upload — no roll CSV is parsed before it passes the virus scan (BI-10), whatever the trigger path';
    }

    public function cites(): string
    {
        return '2.12 · BI-10 · S04E STEP 1';
    }

    public function tags(): array
    {
        return ['S04E'];
    }

    public function check(): AssertionResult
    {
        $leaks = DB::select(
            "SELECT DISTINCT b.id
             FROM enrolment_batch_rows r
             JOIN enrolment_batches b ON b.id = r.batch_id
             LEFT JOIN uploads u ON u.id = b.upload_id
             WHERE u.id IS NULL OR u.status <> 'clean'"
        );

        if ($leaks !== []) {
            return AssertionResult::fail(
                count($leaks).' batch(es) have parsed rows under an upload that is not scan-clean — a file parsed before BI-10 clearance'
            );
        }

        return AssertionResult::pass('every parsed batch row sits under a scan-clean upload (BI-10 gate holds)');
    }
}
