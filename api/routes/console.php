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
