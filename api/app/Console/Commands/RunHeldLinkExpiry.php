<?php

namespace App\Console\Commands;

use App\Services\Identity\LinkageService;
use Illuminate\Console\Command;

/**
 * S04C STEP 3 (Leo 1b): a held link that never materialised reaches its terminal
 * exit — expiry. Runs daily; console context is system, so the expiry writes need
 * no elevation. Expiries surface in the queue-age reporting (STEP 4).
 */
class RunHeldLinkExpiry extends Command
{
    protected $signature = 'held-links:expire';

    protected $description = 'Expire held links past their TTL — the terminal exit for an unmaterialised claim (Leo 1b)';

    public function handle(LinkageService $service): int
    {
        $expired = $service->expireHeldLinks();
        $this->info("Held-link expiry: {$expired} held link(s) expired.");

        return self::SUCCESS;
    }
}
