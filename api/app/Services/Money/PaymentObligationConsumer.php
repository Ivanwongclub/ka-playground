<?php

namespace App\Services\Money;

use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * The outbox CONSUMER (Q1 pattern): reads unconsumed obligations AFTER the
 * claim transaction committed and issues the money artifact — order + deadline
 * clock + payment.requested for family-paid (OD-43/50b); order only for
 * school-settled (the consolidated invoice covers it, OD-53 — no family task).
 * Idempotent end to end: a crash between issuance and consumption re-scans
 * into OrderService's duplicate-returns-original path. Never holds any lock
 * the Team Formation race contends; never runs under provider IO.
 */
class PaymentObligationConsumer
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly AuditService $audit,
        private readonly ConsolidatedInvoiceService $invoices,
    ) {}

    /** @return int obligations consumed ($limit exists so tests can kill mid-batch) */
    public function consume(?int $limit = null): int
    {
        $query = DB::table('payment_obligations')->whereNull('consumed_at')->orderBy('created_at');
        if ($limit !== null) {
            $query->limit($limit);
        }
        $consumed = 0;
        foreach ($query->get() as $obligation) {
            $deadlineDays = (int) (json_decode((string) DB::table('wizard_sections')
                ->where('programme_id', $obligation->programme_id)->where('section_key', 'fees')
                ->value('data'), true)['payment_deadline_days'] ?? 7); // OD-43 default

            $order = $this->orders->issueForEnrolment(
                $obligation->enrolment_id, $obligation->payer_party,
                $obligation->payer_school_id !== null ? (int) $obligation->payer_school_id : null,
                null, $deadlineDays,
            );
            DB::table('payment_obligations')->where('id', $obligation->id)
                ->update(['consumed_at' => now(), 'order_id' => $order->id]);

            if (in_array($obligation->payer_party, ['guardian', 'student'], true)) {
                // OD-50b: the payment surfaces as a PORTAL TASK; S09 delivers
                \App\Events\PaymentRequested::dispatch($order->id, $obligation->enrolment_id, (string) $order->payment_due_at);
            } elseif ($obligation->payer_party === 'school') {
                // OD-25 / S04F STEP 2: no family task — the order joins the school's
                // (school, programme) consolidated invoice (the receivable covers it).
                $this->invoices->coverOrder($order);
            }
            $this->audit->record('payment_obligation', $obligation->id, 'payment_obligation.consumed',
                toState: 'consumed', programmeId: (int) $obligation->programme_id,
                payloadAfter: ['order_id' => $order->id, 'payer_party' => $obligation->payer_party]);
            $consumed++;
        }

        return $consumed;
    }
}
