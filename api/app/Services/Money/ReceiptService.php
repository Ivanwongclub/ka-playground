<?php

namespace App\Services\Money;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gapless receipts (BI-2): the number is claimed from the sequence row under
 * SELECT … FOR UPDATE INSIDE the issuing transaction — never pre-reserved,
 * never issued outside one (BI-3). Receipts are immutable at the DB.
 */
class ReceiptService
{
    public function __construct(private readonly AuditService $audit) {}

    public function issue(string $orderId, ?User $actor): object
    {
        $order = DB::table('orders')->where('id', $orderId)->first()
            ?? throw new RuntimeException("Order {$orderId} not found");

        $id = (string) Str::uuid7();
        DB::transaction(function () use ($id, $order): void {
            $sequence = DB::selectOne("SELECT next_number FROM receipt_sequences WHERE key = 'KAP' FOR UPDATE");
            DB::table('receipts')->insert([
                'id' => $id, 'order_id' => $order->id, 'sequence_key' => 'KAP',
                'receipt_number' => (int) $sequence->next_number,
                'amount_minor' => (int) $order->total_amount_minor, 'currency' => $order->currency,
                'issued_by' => null, 'issued_at' => now(), 'created_at' => now(),
            ]);
            DB::table('receipt_sequences')->where('key', 'KAP')->increment('next_number');
        });
        $receipt = DB::table('receipts')->where('id', $id)->first();
        $this->audit->record('receipt', $id, 'receipt.issued',
            toState: 'issued', programmeId: (int) $order->programme_id,
            payloadAfter: ['order_id' => $order->id, 'receipt_number' => $receipt->receipt_number,
                'amount_minor' => (int) $order->total_amount_minor],
            actor: $actor);

        return $receipt;
    }
}
