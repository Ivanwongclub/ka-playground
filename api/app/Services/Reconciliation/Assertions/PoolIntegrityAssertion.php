<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Consent\ConsentSigningService;
use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * OD-34/50a: the pool gate never leaks — In Pool implies consent satisfied and
 * Pending Consent implies it is not. Rows younger than the job-latency grace
 * window are excluded (the gate runs asynchronously by design).
 */
class PoolIntegrityAssertion implements Assertion
{
    public function key(): string
    {
        return 'enrolments.pool_integrity';
    }

    public function proves(): string
    {
        return 'every In Pool enrolment has consent satisfied and every settled Pending Consent one does not — the gate never leaks either way';
    }

    public function cites(): string
    {
        return 'OD-34 · OD-50a';
    }

    public function tags(): array
    {
        return ['S04A'];
    }

    public function check(): AssertionResult
    {
        $consent = app(ConsentSigningService::class);
        $rows = DB::table('enrolments')->whereIn('status', ['in_pool', 'pending_consent'])
            ->where('updated_at', '<', now()->subMinutes(10))->get();
        $violations = [];
        foreach ($rows as $row) {
            $satisfied = $consent->consentSatisfied((int) $row->programme_id, (int) $row->student_id);
            if (($row->status === 'in_pool') !== $satisfied) {
                $violations[] = "{$row->id} ({$row->status}, satisfied=".var_export($satisfied, true).')';
            }
        }

        if ($violations !== []) {
            return AssertionResult::fail(count($violations).' gate leak(s): '.implode(' · ', array_slice($violations, 0, 5)));
        }

        return AssertionResult::pass($rows->count().' settled pool-adjacent enrolment(s) checked'.($rows->isEmpty() ? ' (vacuous)' : ', gate holds both ways'));
    }
}
