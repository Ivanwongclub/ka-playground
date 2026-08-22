<?php

namespace App\Console\Commands;

use App\Services\Consent\ConsentSigningService;
use Illuminate\Console\Command;

/**
 * S-TTL-1: an issued consent request that was never signed reaches its terminal exit — expiry.
 * Runs daily; console context is system, so the expiry writes need no elevation.
 *
 * It expires the REQUEST and nothing else: the enrolment is left where it is (BI-7 — nothing writes
 * Withdrawn outside the withdrawal workflow), and no replacement request is issued (re-consent is a
 * deliberate, audited act, not a cron side effect). Expired rows surface to ops through the existing
 * consent read; see the S-TTL-1 report for where.
 */
class RunConsentExpiry extends Command
{
    protected $signature = 'consents:expire';

    protected $description = 'Expire consent requests past their TTL — sent/viewed only; decided requests are never touched (S-TTL-1)';

    public function handle(ConsentSigningService $service): int
    {
        $expired = $service->expireOverdue();
        $this->info("Consent expiry: {$expired} consent request(s) expired.");

        return self::SUCCESS;
    }
}
