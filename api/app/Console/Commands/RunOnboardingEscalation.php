<?php

namespace App\Console\Commands;

use App\Services\Identity\OnboardingQueueService;
use Illuminate\Console\Command;

/**
 * S04C STEP 4 (2.28 Q5): the daily sweep that escalates onboarding-queue items
 * past the threshold into onboarding_exceptions. Console context is system, so
 * the escalation writes need no elevation. Runs before reconcile so
 * queue.escalation_liveness holds.
 */
class RunOnboardingEscalation extends Command
{
    protected $signature = 'onboarding:escalate';

    protected $description = 'Escalate onboarding-queue items past the threshold into open exceptions (2.28 Q5)';

    public function handle(OnboardingQueueService $service): int
    {
        $raised = $service->escalate();
        $this->info("Onboarding escalation: {$raised} queue item(s) escalated.");

        return self::SUCCESS;
    }
}
