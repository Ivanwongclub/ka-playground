<?php

namespace App\Console\Commands;

use App\Services\Sessions\SessionAdvancementService;
use Illuminate\Console\Command;

/** S06-7 (2.3): advance sessions past their time (published/full -> in_progress -> completed). */
class RunSessionAdvancement extends Command
{
    protected $signature = 'sessions:advance';

    protected $description = 'Advance sessions past their scheduled time (2.3)';

    public function handle(SessionAdvancementService $service): int
    {
        $result = $service->run();
        $this->info("Session advancement: {$result['started']} started, {$result['completed']} completed.");

        return self::SUCCESS;
    }
}
