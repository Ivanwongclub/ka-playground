<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * S06-3 — a waitlisted booking was auto-promoted to booked (a slot opened).
 * S09 delivers the notification; S06 fires the event.
 */
class BookingPromoted
{
    use Dispatchable;

    public function __construct(
        public string $sessionId,
        public string $bookingId,
        public int $studentId,
    ) {}
}
