<?php

namespace App\Services\Money;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The money side of a withdrawal (2.1 → 2.17). Runs ONLY against an APPROVED
 * S04A withdrawal (BI-7). FULL-only (OD-48); destination = original payer paid
 * by the academy (OD-25). OD-54 school-settled precedence:
 *   credit note ALWAYS → if the invoice is unpaid the balance drops; if it is
 *   already paid the credit note is accompanied by a refund-to-school.
 * Family-paid: a refund to the payer iff the order was actually paid.
 * System-context (called after ApplyWithdrawal); the 'requested' refund then
 * runs the 2.17 machine under BI-9.
 */
class WithdrawalSettlementService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{credit_note_id: ?string, refund_id: ?string} */
    public function settle(string $withdrawalRequestId, ?User $actor = null): array
    {
        $wr = DB::table('withdrawal_requests')->where('id', $withdrawalRequestId)->first()
            ?? throw new RuntimeException("Withdrawal request {$withdrawalRequestId} not found");
        if ($wr->status !== 'approved') {
            // a refund/credit note only ever traces to an APPROVED withdrawal
            abort(409, 'Settlement requires an APPROVED withdrawal (2.1/BI-7) — refused');
        }
        if (DB::table('refunds')->where('withdrawal_request_id', $withdrawalRequestId)->where('status', '<>', 'rejected')->exists()
            || DB::table('credit_notes')->where('withdrawal_request_id', $withdrawalRequestId)->exists()) {
            // idempotent: already settled
            return ['credit_note_id' => null, 'refund_id' => null];
        }

        $order = DB::table('orders')->where('enrolment_id', $wr->enrolment_id)->where('status', '<>', 'cancelled')->first();
        if ($order === null) {
            return ['credit_note_id' => null, 'refund_id' => null]; // nothing billed
        }
        $creditNoteId = null;
        $refundId = null;

        if ($order->payer_party === 'school') {
            // OD-54: credit note ALWAYS for a school-settled order
            $invoice = $order->consolidated_invoice_id !== null
                ? DB::table('consolidated_invoices')->where('id', $order->consolidated_invoice_id)->first()
                : null;
            $creditNoteId = $this->writeCreditNote($order, $wr, $actor, $order->consolidated_invoice_id);
            if ($invoice !== null) {
                // the balance drops by the credit (OD-54 invariant: balance = original − Σ credits)
                DB::table('consolidated_invoices')->where('id', $invoice->id)
                    ->update(['balance_minor' => (int) $invoice->balance_minor - (int) $order->total_amount_minor, 'updated_at' => now()]);
                if ($invoice->status === 'paid') {
                    // already paid → the credit becomes a refund owed to the SCHOOL
                    $refundId = $this->writeRefund($order, $wr, 'school', (int) $invoice->school_id, $actor);
                }
            }
        } elseif ($order->status === 'paid') {
            // family-paid AND actually paid → refund to the original payer (OD-25)
            $refundId = $this->writeRefund($order, $wr, $order->payer_party, null, $actor);
        }
        // family-paid but unpaid: nothing to move — the withdrawal just releases

        return ['credit_note_id' => $creditNoteId, 'refund_id' => $refundId];
    }

    private function writeCreditNote(object $order, object $wr, ?User $actor, ?string $invoiceId): string
    {
        $id = (string) Str::uuid7();
        DB::table('credit_notes')->insert([
            'id' => $id, 'order_id' => $order->id, 'consolidated_invoice_id' => $invoiceId,
            'withdrawal_request_id' => $wr->id, 'amount_minor' => (int) $order->total_amount_minor,
            'currency' => $order->currency, 'reason' => "withdrawal {$wr->id} approved (OD-54)",
            'created_by' => $actor?->id, 'created_at' => now(),
        ]);
        $this->audit->record('credit_note', $id, 'credit_note.issued',
            programmeId: (int) $order->programme_id,
            payloadAfter: ['order_id' => $order->id, 'invoice_id' => $invoiceId, 'amount_minor' => (int) $order->total_amount_minor],
            actor: $actor);

        return $id;
    }

    private function writeRefund(object $order, object $wr, string $destParty, ?int $destSchoolId, ?User $actor): string
    {
        $id = (string) Str::uuid7();
        // FULL only (OD-48): the amount IS the order total, by construction
        DB::table('refunds')->insert([
            'id' => $id, 'order_id' => $order->id, 'withdrawal_request_id' => $wr->id,
            'amount_minor' => (int) $order->total_amount_minor, 'currency' => $order->currency,
            'destination_party' => $destParty, 'destination_school_id' => $destSchoolId,
            'status' => 'requested', 'requested_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('refund', $id, 'refund.requested',
            toState: 'requested', programmeId: (int) $order->programme_id,
            payloadAfter: ['order_id' => $order->id, 'destination_party' => $destParty,
                'amount_minor' => (int) $order->total_amount_minor, 'withdrawal_request_id' => $wr->id], actor: $actor);

        return $id;
    }
}
