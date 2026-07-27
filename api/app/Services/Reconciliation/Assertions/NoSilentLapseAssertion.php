<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * deadlines.no_silent_lapse — deferred from S04B, lands at S05-4 now that the
 * resolution machinery exists (Leo ruling 2026-07-27; card STEP 6 predicate).
 *
 * EXACT PREDICATE: no FAMILY-PAID order past payment_due_at + grace (or the
 * member's grace-extended deadline), still unpaid, WITHOUT BOTH
 *   (a) its SYSTEM-actor lapse audit event, AND
 *   (b) a team_members suspension record OR an FR066 lapse exception.
 *
 * The deadline clock is S04B's (orders.payment_due_at); the resolution
 * (suspension + exception) is S05-4's — so the assertion is never a permanently
 * red trap (the R15 anti-pattern): the lapse job clears every row it scans.
 * Scope matches the job exactly (a LIVE team_member), so a dissolved/cancelled
 * order never shows as a false silent lapse. Vacuous-aware until volume.
 */
class NoSilentLapseAssertion implements Assertion
{
    public function key(): string
    {
        return 'deadlines.no_silent_lapse';
    }

    public function proves(): string
    {
        return 'no family-paid order past its payment deadline + grace, still unpaid, lacks BOTH its SYSTEM-actor lapse audit AND a suspension or FR066 lapse exception (no lapse goes unrecorded)';
    }

    public function cites(): string
    {
        return 'OD-45 · FR066 · 2.19';
    }

    public function tags(): array
    {
        return ['S05'];
    }

    public function check(): AssertionResult
    {
        $grace = (int) config('teams.lapse_grace_days', 7);

        // family-paid, unpaid orders whose LIVE member is past the effective deadline
        $scrutinised = DB::table('orders as o')
            ->join('team_members as tm', function ($j): void {
                $j->on('tm.enrolment_id', '=', 'o.enrolment_id')->whereIn('tm.status', ['active', 'suspended']);
            })
            ->where('o.payer_party', 'guardian')->where('o.status', 'issued')
            ->whereRaw("COALESCE(tm.grace_until, o.payment_due_at + (? || ' days')::interval) < now()", [$grace]);

        $total = (clone $scrutinised)->count();

        $silent = (clone $scrutinised)
            ->whereRaw("NOT (
                EXISTS (SELECT 1 FROM audit_events ae WHERE ae.entity_type = 'order' AND ae.entity_id = o.id::text AND ae.action = 'order.lapsed' AND ae.actor_role = 'system')
                AND (
                    tm.status = 'suspended'
                    OR EXISTS (SELECT 1 FROM team_exceptions te WHERE te.type = 'lapse' AND te.enrolment_id = o.enrolment_id)
                )
            )")
            ->count();

        return $silent > 0
            ? AssertionResult::fail("{$silent} lapsed family order(s) with no SYSTEM lapse audit + suspension/exception — a SILENT lapse (OD-45); run teams:run-lapse-detection")
            : AssertionResult::pass("{$total} past-deadline family order(s) checked".($total === 0 ? ' (vacuous)' : ', all recorded (audit + suspension/exception)'));
    }
}
