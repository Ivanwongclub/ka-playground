<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S06-6 (2.10) — reminder-ladder liveness. The reminder-ladder MECHANISM is S09's
 * (S06 registers the guard now, per the card: "2.10 register · S09 full"). The
 * surface does not exist yet, so this assertion is TABLE-GUARDED: vacuous until
 * `reminder_ladders` (with `next_run_at`) is built — no schema is assumed here.
 * Once S09 lands the ladder, this activates: no OPEN ladder may have its
 * `next_run_at` more than an hour in the past (a stalled ladder = missed reminders).
 */
class LadderLivenessAssertion implements Assertion
{
    public function key(): string
    {
        return 'ladders.liveness';
    }

    public function proves(): string
    {
        return 'no open reminder ladder has a next_run_at more than an hour overdue — reminders are not silently stalled (2.10; vacuous until S09 builds the ladder)';
    }

    public function cites(): string
    {
        return '2.10';
    }

    public function tags(): array
    {
        return ['S06'];
    }

    public function check(): AssertionResult
    {
        if (! Schema::hasTable('reminder_ladders')) {
            return AssertionResult::pass('no reminder-ladder surface yet — guard registered, activates when S09 builds it (vacuous)');
        }
        $stalled = DB::table('reminder_ladders')
            ->where('status', 'open')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<', now()->subHour())
            ->count();

        return $stalled > 0
            ? AssertionResult::fail("{$stalled} open reminder ladder(s) overdue by >1h — reminders stalled (2.10)")
            : AssertionResult::pass('reminder ladders live (none overdue by >1h)');
    }
}
