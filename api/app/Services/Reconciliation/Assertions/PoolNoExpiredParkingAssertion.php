<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 (OD-35) — the parking loop-breaker held. No parked roll-forward sits open
 * past its backstop window: the backstop job auto-refunds + releases every expired
 * parking (→ auto_released). An OPEN parked_rollforward with backstop_at in the
 * past means the sweep did not run — a student stuck in the loop OD-35 forbids.
 */
class PoolNoExpiredParkingAssertion implements Assertion
{
    public function key(): string
    {
        return 'pool.no_expired_parking';
    }

    public function proves(): string
    {
        return 'no parked roll-forward remains open past its backstop window — the 90-day auto-refund+release sweep never leaves a student stuck (OD-35)';
    }

    public function cites(): string
    {
        return 'OD-35';
    }

    public function tags(): array
    {
        return ['S05'];
    }

    public function check(): AssertionResult
    {
        $expired = DB::table('team_exceptions')
            ->where('type', 'parked_rollforward')->where('status', 'open')
            ->whereNotNull('backstop_at')->where('backstop_at', '<', now())
            ->count();
        $total = (int) DB::table('team_exceptions')->where('type', 'parked_rollforward')->count();

        return $expired > 0
            ? AssertionResult::fail("{$expired} parked roll-forward(s) open past their backstop — the auto-refund+release sweep did not run (OD-35); run teams:run-parking-backstop")
            : AssertionResult::pass("{$total} parked roll-forward(s) checked".($total === 0 ? ' (vacuous)' : ', none expired unswept'));
    }
}
