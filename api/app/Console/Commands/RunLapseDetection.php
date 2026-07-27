<?php

namespace App\Console\Commands;

use App\Services\Teams\LapseDetectionService;
use Illuminate\Console\Command;

/**
 * S05-4 (OD-45): scan family-paid unpaid orders past their deadline+grace,
 * suspend the member, raise the lapse (+ below_min) exception. Scheduled daily.
 */
class RunLapseDetection extends Command
{
    protected $signature = 'teams:run-lapse-detection';

    protected $description = 'Detect lapsed family payments: suspend the member, raise the lapse/below-min exception (OD-45)';

    public function handle(LapseDetectionService $service): int
    {
        $result = $service->run();
        $this->info("Lapse detection: {$result['lapsed']} member(s) suspended, {$result['below_min']} team(s) dropped below minimum.");

        return self::SUCCESS;
    }
}
