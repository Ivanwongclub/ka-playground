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
