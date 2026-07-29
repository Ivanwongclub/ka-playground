<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S06-7 — attendance was only ever taken on a session that RAN. Attendance is
 * recorded on the booking (attended|no_show); the mark() gate refuses unless the
 * session is In Progress or Completed. A booking marked attended/no_show whose
 * session never reached in_progress/completed (still draft/published/full) means
 * that gate was bypassed — the meaningful red (not a structurally-impossible
 * orphan). "attendance == attended bookings" by construction; this guards HOW.
 */
class AttendanceIntegrityAssertion implements Assertion
{
    public function key(): string
    {
        return 'sessions.attendance_integrity';
    }

    public function proves(): string
    {
        return 'every attendance mark (attended/no_show) sits on a session that reached In Progress or Completed — no attendance taken on a session that never ran';
    }

    public function cites(): string
    {
        return '2.3 · FR012';
    }

    public function tags(): array
    {
        return ['S06'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::table('session_bookings as sb')
            ->join('programme_sessions as ps', 'ps.id', '=', 'sb.session_id')
            ->whereIn('sb.status', ['attended', 'no_show'])
            ->whereIn('ps.status', ['draft', 'published', 'full'])
            ->count();
        $total = (int) DB::table('session_bookings')->whereIn('status', ['attended', 'no_show'])->count();

        return $bad > 0
            ? AssertionResult::fail("{$bad} attendance mark(s) on a session that never ran (still draft/published/full) — the attendance gate was bypassed (2.3)")
            : AssertionResult::pass("{$total} attendance mark(s) checked".($total === 0 ? ' (vacuous)' : ', all on sessions that ran'));
    }
}
