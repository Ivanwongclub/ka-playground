<?php

namespace App\Console\Commands;

use App\Services\Enrolments\EnrolmentActivationService;
use Illuminate\Console\Command;

/**
 * S06-1 (R3): activate confirmed enrolments whose programme has started.
 * Scheduled daily, before the reconciliation run.
 */
class RunEnrolmentActivations extends Command
{
    protected $signature = 'enrolments:run-activations';

    protected $description = 'Activate confirmed enrolments whose programme has started (R3)';

    public function handle(EnrolmentActivationService $service): int
    {
        $result = $service->run();
        $this->info("Enrolment activation: {$result['activated']} enrolment(s) moved confirmed → active.");

        return self::SUCCESS;
    }
}
