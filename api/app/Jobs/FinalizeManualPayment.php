<?php

namespace App\Jobs;

use App\Services\Audit\AuditService;
use App\Services\Money\ReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** After BI-9 confirmation: order → paid + gapless receipt, in system context. */
class FinalizeManualPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $paymentId) {}

    public function handle(ReceiptService $receipts, AuditService $audit): void
    {
        $payment = DB::table('payments')->where('id', $this->paymentId)->first();
        if ($payment === null || $payment->status !== 'confirmed') {
            return;
        }
        $order = DB::table('orders')->where('id', $payment->order_id)->first();
        if ($order === null || $order->status !== 'issued') {
            return; // idempotent (already paid) or not finalisable
        }
        DB::table('orders')->where('id', $order->id)->update(['status' => 'paid', 'updated_at' => now()]);
        $receipt = $receipts->issue($order->id, null);
        $audit->record('order', $order->id, 'order.paid',
            fromState: 'issued', toState: 'paid', programmeId: (int) $order->programme_id,
            payloadAfter: ['payment_id' => $payment->id, 'receipt_number' => $receipt->receipt_number]);
    }
}
