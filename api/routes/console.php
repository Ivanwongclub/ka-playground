<?php

use Illuminate\Support\Facades\Schedule;

// Nightly reconciliation suite (Spec P3 / SR010). 03:00 HKT, off-peak;
// timestamps stored UTC per OD-16. A run that fails to start is itself an
// alert (P4) — the schedule pings on failure via the command's own exit code.
Schedule::command('reconcile:run')
    ->timezone('Asia/Hong_Kong')
    ->dailyAt('03:00')
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::critical('Nightly reconciliation run failed or did not complete');
    });

// S05-3 formation-deadline machinery (OD-33/35). Daily, off-peak, HKT. Both are
// idempotent SYSTEM jobs; the backstop runs after deadlines so a just-flagged
// team never trips it the same day.
Schedule::command('teams:run-deadlines')
    ->timezone('Asia/Hong_Kong')
    ->dailyAt('02:30');
Schedule::command('teams:run-parking-backstop')
    ->timezone('Asia/Hong_Kong')
    ->dailyAt('02:40');

// S05-4 non-payment lapse cascade (OD-45). Runs before the deadline job so a
// same-day suspension is visible when below-min teams are assessed.
Schedule::command('teams:run-lapse-detection')
    ->timezone('Asia/Hong_Kong')
    ->dailyAt('02:20');

// S06-1 enrolment activation (R3). Runs before the reconciliation (03:00) so a
// started programme's confirmed enrolments are Active by the time
// enrolments.activation_liveness is checked.
Schedule::command('enrolments:run-activations')
    ->timezone('Asia/Hong_Kong')
    ->dailyAt('02:15');

// S06-7 session advancement (2.3). Every 5 minutes so sessions open/close near
// their real time; also before reconcile so sessions.no_stale_published holds.
Schedule::command('sessions:advance')
    ->everyFiveMinutes();

// S04C-3 held-link expiry (Leo 1b). Daily, off-peak, HKT. The terminal exit for
// an unmaterialised form-claim; runs before reconcile so held_links.expiry holds.
Schedule::command('held-links:expire')
    ->timezone('Asia/Hong_Kong')
    ->dailyAt('02:10');
