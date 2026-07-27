<?php

namespace App\Console\Commands;

use App\Services\Teams\ParkingBackstopService;
use Illuminate\Console\Command;

/**
 * S05-3 (OD-35, loop-breaker): the 90-day parking backstop. Any parked
 * roll-forward past its window is force-resolved — full auto-refund of a paid
 * order (out of BI-9 per OD-47) then release. Scheduled daily.
 */
class RunParkingBackstop extends Command
{
    protected $signature = 'teams:run-parking-backstop';

    protected $description = 'Fire the 90-day parking backstop: auto-refund + release expired roll-forwards (OD-35)';

    public function handle(ParkingBackstopService $service): int
    {
        $result = $service->run();
        $this->info("Parking backstop fired: {$result['refunded']} refunded, {$result['released']} released.");

        return self::SUCCESS;
    }
}
