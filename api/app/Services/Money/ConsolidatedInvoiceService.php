<?php

namespace App\Services\Money;

use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S04F STEP 2 (OD-25) — the invoice ISSUANCE the dormant table was waiting for.
 * When a school-payer order is issued (the PaymentObligationConsumer seam), the
 * order is attached to its `(school, programme)` consolidated invoice — a
 * receivable the academy is owed (the school is a payer, never a collector).
 *
 * There is NO batch_id — an invoice is "what this school owes for this
 * programme", aggregating every school-payer order for the pair (bulk or not).
 *
 * Idempotency is BY CONSTRUCTION:
 *   - find-or-create the ONE invoice per (school, programme);
 *   - an order already covered (status + consolidated_invoice_id) is never
 *     re-attached;
 *   - `original_amount_minor` is RECOMPUTED from the covered-order set each run,
 *     never blind-incremented — so a re-run cannot double-count. It grows only
 *     as orders attach (monotonic); corrections are credit notes, never edits
 *     (BI-5 extended to invoices). A covered order is a receivable — NOT paid,
 *     NO receipt until the school actually pays (BI-2).
 */
class ConsolidatedInvoiceService
{
    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
    ) {}

    /** Attach a school-payer order to its (school, programme) consolidated invoice. */
    public function coverOrder(object $order): void
    {
        if ($order->payer_party !== 'school' || $order->payer_school_id === null) {
            return; // only school-payer orders are invoiced
        }

        $this->scope->asSystem(
            'S04F STEP 2 consolidated invoice issuance (OD-25): attaches a school-payer order to its (school, programme) consolidated invoice (find-or-create), marks the order covered_by_invoice, and recomputes the invoice original from the covered-order set. The invoice is a school-wide receivable outside any single actor\'s derived scope; the table is system-write by construction. Idempotent recompute — never double-counts.',
            function () use ($order): void {
                $schoolId = (int) $order->payer_school_id;
                $programmeId = (int) $order->programme_id;

                $invoiceId = DB::table('consolidated_invoices')
                    ->where('school_id', $schoolId)->where('programme_id', $programmeId)
                    ->value('id');
                if ($invoiceId === null) {
                    $invoiceId = (string) Str::uuid7();
                    DB::table('consolidated_invoices')->insert([
                        'id' => $invoiceId, 'school_id' => $schoolId, 'programme_id' => $programmeId,
                        'original_amount_minor' => 0, 'balance_minor' => 0, 'currency' => $order->currency,
                        'status' => 'issued', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->audit->record('consolidated_invoice', $invoiceId, 'consolidated_invoice.issued',
                        toState: 'issued', programmeId: $programmeId,
                        payloadAfter: ['school_id' => $schoolId, 'currency' => $order->currency]);
                }

                // Attach the order — only if not already covered (idempotent).
                if (($order->consolidated_invoice_id ?? null) === null && $order->status !== 'covered_by_invoice') {
                    DB::table('orders')->where('id', $order->id)->update([
                        'status' => 'covered_by_invoice', 'consolidated_invoice_id' => $invoiceId, 'updated_at' => now(),
                    ]);
                    $this->audit->record('order', $order->id, 'order.covered',
                        fromState: $order->status, toState: 'covered_by_invoice', programmeId: $programmeId,
                        payloadAfter: ['consolidated_invoice_id' => $invoiceId, 'amount_minor' => (int) $order->total_amount_minor]);
                }

                // Recompute original from the covered set (never blind-increment); balance = original − Σ credit notes.
                $original = (int) DB::table('orders')
                    ->where('consolidated_invoice_id', $invoiceId)->where('status', 'covered_by_invoice')
                    ->sum('total_amount_minor');
                $credits = (int) DB::table('credit_notes')->where('consolidated_invoice_id', $invoiceId)->sum('amount_minor');
                DB::table('consolidated_invoices')->where('id', $invoiceId)->update([
                    'original_amount_minor' => $original, 'balance_minor' => $original - $credits, 'updated_at' => now(),
                ]);
            },
        );
    }
}
