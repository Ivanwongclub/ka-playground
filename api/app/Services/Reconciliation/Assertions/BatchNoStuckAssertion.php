<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S04E STEP 3 — liveness. A batch may sit in a TRANSIENT state legitimately
 * (scanning waits on the async scan; validating parses; committing runs the
 * whole roll), so the window is generous — a big-but-healthy batch must not
 * false-red. A batch past the window is genuinely stuck (a job died) and reds,
 * so the transient states are never a silent dead-end.
 */
class BatchNoStuckAssertion implements Assertion
{
    private const TRANSIENT = ['scanning', 'validating', 'committing'];

    public function key(): string
    {
        return 'batches.no_stuck';
    }

    public function proves(): string
    {
        return 'no enrolment batch sits in a transient state (scanning/validating/committing) past its job-timeout window — a stuck batch (dead job) surfaces instead of hanging silently';
    }

    public function cites(): string
    {
        return 'Spec Part H H2 · S04E STEP 3';
    }

    public function tags(): array
    {
        return ['S04E'];
    }

    public function check(): AssertionResult
    {
        $minutes = (int) config('uploads.batch_stuck_minutes', 30);
        $cutoff = now()->subMinutes($minutes);

        $stuck = DB::table('enrolment_batches')
            ->whereIn('status', self::TRANSIENT)
            ->where('updated_at', '<', $cutoff)
            ->get(['id', 'status']);

        if ($stuck->isNotEmpty()) {
            return AssertionResult::fail(
                $stuck->count()." batch(es) stuck in a transient state past the {$minutes}-minute window — a dead job (e.g. ".$stuck->first()->status.')'
            );
        }

        return AssertionResult::pass("no batch stuck in scanning/validating/committing past the {$minutes}-minute window");
    }
}
