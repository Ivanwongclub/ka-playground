<?php

namespace App\Jobs;

use App\Services\Money\PaymentObligationConsumer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Dispatched after a claim commits (S05 wires Team Formation to it); also safe to re-run any time. */
class ConsumePaymentObligations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function handle(PaymentObligationConsumer $consumer): void
    {
        $consumer->consume();
    }
}
