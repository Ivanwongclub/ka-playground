<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 — every seat claim was WHOLE: the seat count claimed equalled the team's
 * member count AT Team Formation. Judged against the CONFIRM-TIME fact — the immutable
 * `team.confirmed` audit payload (seats_claimed, member_count) — NOT a live join,
 * because a legitimate post-Team Formation change (assign, dissolve) would move the live
 * count away from what was claimed. No partial claim ever existed.
 */
class CapacityClaimsWholeAssertion implements Assertion
{
    public function key(): string
    {
        return 'capacity.claims_are_whole';
    }

    public function proves(): string
    {
        return 'every recorded Team Formation claimed exactly as many seats as it had members at confirm time (team.confirmed audit: seats_claimed = member_count > 0) — no partial claims';
    }

    public function cites(): string
    {
        return 'OD-32 · BI-3';
    }

    public function tags(): array
    {
        return ['S05'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::table('audit_events')
            ->where('action', 'team.confirmed')
            ->where(function ($q): void {
                $q->whereRaw("(payload_after->>'seats_claimed') IS DISTINCT FROM (payload_after->>'member_count')")
                    ->orWhereRaw("COALESCE((payload_after->>'seats_claimed')::int, 0) <= 0");
            })
            ->count();
        $total = (int) DB::table('audit_events')->where('action', 'team.confirmed')->count();

        return $bad > 0
            ? AssertionResult::fail("{$bad} Team Formation claim(s) where seats_claimed ≠ member_count (a partial claim, OD-32)")
            : AssertionResult::pass("{$total} Team Formation claim(s) checked".($total === 0 ? ' (vacuous)' : ', all whole (seats = members)'));
    }
}
