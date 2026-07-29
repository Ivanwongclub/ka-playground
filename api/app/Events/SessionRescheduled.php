<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * S06-2 (2.3) — a session was rescheduled. S09 delivers the re-notification
 * (with the clash list); S06 only fires the event.
 */
class SessionRescheduled
{
    use Dispatchable;

    /** @param array<int, int> $clashingStudentIds */
    public function __construct(
        public string $sessionId,
        public string $fromStartsAt,
        public string $toStartsAt,
        public array $clashingStudentIds,
    ) {}
}
