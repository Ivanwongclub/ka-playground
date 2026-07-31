<?php

namespace Tests\Support;

use App\Services\Uploads\VirusScanner;
use RuntimeException;

/**
 * A scanner that is DOWN: the probe reports unavailable and any scan throws.
 * Used to exercise the S04E fail-closed edge (D-4) — clamd unreachable must
 * refuse batch-csv intake with 503, persisting nothing.
 */
class UnreachableScanner implements VirusScanner
{
    public function scan(string $contents): ?string
    {
        throw new RuntimeException('clamd unreachable (test double)');
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
