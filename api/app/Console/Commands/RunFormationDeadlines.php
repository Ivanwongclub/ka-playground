<?php

namespace App\Console\Commands;

use App\Services\Teams\FormationDeadlineService;
use Illuminate\Console\Command;

/**
 * S05-3 (OD-33): at the formation deadline, auto-submit size-compliant forming
 * teams and flag the rest. Scheduled daily; safe to re-run (idempotent).
 */
class RunFormationDeadlines extends Command
{
    protected $signature = 'teams:run-deadlines';

    protected $description = 'Process formation deadlines: auto-submit compliant teams, flag non-compliant ones (OD-33)';

    public function handle(FormationDeadlineService $service): int
    {
        $result = $service->run();
        $this->info("Formation deadlines processed: {$result['auto_submitted']} auto-submitted, {$result['flagged']} flagged.");

        return self::SUCCESS;
    }
}
