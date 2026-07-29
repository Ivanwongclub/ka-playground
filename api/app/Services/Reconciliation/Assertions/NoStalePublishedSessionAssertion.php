<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S06-7 (2.3) — no session sits Published/Full/In-Progress after its time has
 * passed: the `sessions:advance` job moves a session past its start (→ in_progress)
 * and end (→ completed), so a session whose end is in the past MUST be terminal
 * (completed/cancelled). One still live past its end means the advancement job
 * did not run — a liveness gap. Draft (never published) and rescheduled sessions
 * are out of scope.
 */
class NoStalePublishedSessionAssertion implements Assertion
{
    public function key(): string
    {
        return 'sessions.no_stale_published';
    }

    public function proves(): string
    {
        return 'no published/full/in-progress session remains live after its end time — the advancement job moves past-time sessions to a terminal state (2.3)';
    }

    public function cites(): string
    {
        return '2.3';
    }

    public function tags(): array
    {
        return ['S06'];
    }

    public function check(): AssertionResult
    {
        $stale = DB::table('programme_sessions')
            ->whereIn('status', ['published', 'full', 'in_progress'])
            ->where('ends_at', '<', now())
            ->count();
        $total = (int) DB::table('programme_sessions')->count();

        return $stale > 0
            ? AssertionResult::fail("{$stale} session(s) still live past their end time — the advancement job did not run (2.3); run sessions:advance")
            : AssertionResult::pass("{$total} session(s) checked".($total === 0 ? ' (vacuous)' : ', none stale past their end'));
    }
}
