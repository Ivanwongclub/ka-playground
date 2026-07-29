<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S06-7 (2.21) — the withdrawal booking cascade held. A Withdrawn enrolment must
 * hold no LIVE booking (booked/waitlisted) on a FUTURE, non-terminal session:
 * ApplyWithdrawal cancels those and releases the waitlist. One left live means the
 * cascade did not run — a withdrawn student still occupying a future seat.
 */
class BookingCascadeLiveAssertion implements Assertion
{
    public function key(): string
    {
        return 'bookings.cascade_live';
    }

    public function proves(): string
    {
        return 'no Withdrawn enrolment holds a live booking on a future session — the 2.21 withdrawal cascade cancelled it and released the waitlist';
    }

    public function cites(): string
    {
        return '2.21';
    }

    public function tags(): array
    {
        return ['S06'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::table('session_bookings as sb')
            ->join('enrolments as e', 'e.id', '=', 'sb.enrolment_id')
            ->join('programme_sessions as ps', 'ps.id', '=', 'sb.session_id')
            ->where('e.status', 'withdrawn')
            ->whereIn('sb.status', ['booked', 'waitlisted'])
            ->where('ps.starts_at', '>', now())
            ->whereNotIn('ps.status', ['cancelled', 'completed'])
            ->count();

        return $bad > 0
            ? AssertionResult::fail("{$bad} live future booking(s) held by a Withdrawn enrolment — the withdrawal cascade did not run (2.21)")
            : AssertionResult::pass('no Withdrawn enrolment holds a live future booking');
    }
}
