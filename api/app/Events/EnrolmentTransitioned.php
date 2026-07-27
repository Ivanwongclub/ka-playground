<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** OD-66: enrolment lifecycle events — raised per transition, delivered by S09. */
class EnrolmentTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly string $enrolmentId,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
