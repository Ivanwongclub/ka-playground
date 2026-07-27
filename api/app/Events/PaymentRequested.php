<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** OD-43/50b/66: the family-paid portal task exists — S09 delivers the notification. */
class PaymentRequested
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
        public readonly string $enrolmentId,
        public readonly string $dueAt,
    ) {}
}
