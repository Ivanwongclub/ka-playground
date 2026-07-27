<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** OD-66: raised now, delivered by S09's notification pipeline. */
class WithdrawalDecided
{
    use Dispatchable;

    public function __construct(public readonly string $requestId, public readonly string $outcome) {}
}
