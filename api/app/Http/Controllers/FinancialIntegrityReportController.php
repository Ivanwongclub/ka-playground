<?php

namespace App\Http\Controllers;

use App\Services\Authz\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S04B audit element: the Financial Integrity Report. Academy-scoped —
 * finance.view OR audit.read (super_admin holds both); it does NOT widen any
 * read set (school admins, families get 403 at the gate). Every figure is a
 * LIVE aggregate computed at request time — there are no cached/counter
 * columns to read, so a report can never disagree with source (GR002/GR007).
 */
class FinancialIntegrityReportController extends Controller
{
    public function index(Request $request, PermissionResolver $resolver): JsonResponse
    {
        $user = $request->user();
        // Gate on ACADEMY capabilities, never finance.view — guardians and
        // school admins hold finance.view through their role for their OWN
        // money, and this report is academy-wide. finance.record is granted
        // only by the finance CAPABILITY; audit.read only by audit_read. So the
        // report widens no family/school read set.
        if (! $resolver->allows($user, 'finance.record') && ! $resolver->allows($user, 'audit.read')) {
            abort(403, 'The Financial Integrity Report is an academy finance-capability / audit surface');
        }

        $byStatus = fn (string $table) => DB::table($table)->groupBy('status')
            ->select('status', DB::raw('count(*) as n'), DB::raw('coalesce(sum(amount_minor),0) as minor'))->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'live_from_source' => true, // no cached totals — every figure below is a request-time aggregate
            'orders' => DB::table('orders')->groupBy('status')
                ->select('status', DB::raw('count(*) as n'), DB::raw('coalesce(sum(total_amount_minor),0) as minor'))->get(),
            'payments_by_origin' => DB::table('payments')->groupBy('origin', 'status')
                ->select('origin', 'status', DB::raw('count(*) as n'), DB::raw('coalesce(sum(amount_minor),0) as minor'))->get(),
            'receipts' => [
                'count' => (int) DB::table('receipts')->count(),
                'sequences' => DB::table('receipt_sequences')->select('key', 'next_number')->get(),
            ],
            'refunds' => $byStatus('refunds'),
            'refunds_by_destination' => DB::table('refunds')->where('status', '<>', 'rejected')->groupBy('destination_party')
                ->select('destination_party', DB::raw('count(*) as n'), DB::raw('coalesce(sum(amount_minor),0) as minor'))->get(),
            'credit_notes' => [
                'count' => (int) DB::table('credit_notes')->count(),
                'minor' => (int) DB::table('credit_notes')->sum('amount_minor'),
            ],
            'consolidated_invoices' => DB::table('consolidated_invoices')->select(
                DB::raw('count(*) as n'), DB::raw('coalesce(sum(original_amount_minor),0) as original_minor'),
                DB::raw('coalesce(sum(balance_minor),0) as balance_minor'))->first(),
            'obligations' => [
                'pending' => (int) DB::table('payment_obligations')->whereNull('consumed_at')->count(),
                'consumed' => (int) DB::table('payment_obligations')->whereNotNull('consumed_at')->count(),
            ],
            // the report surfaces the reconciliation posture itself: Σ credit notes
            // must equal Σ (invoice original − balance) — the OD-54 invariant, live
            'invoice_credit_reconciliation' => [
                'credited_via_notes_minor' => (int) DB::table('credit_notes')->whereNotNull('consolidated_invoice_id')->sum('amount_minor'),
                'invoice_original_minus_balance_minor' => (int) DB::table('consolidated_invoices')->sum(DB::raw('original_amount_minor - balance_minor')),
            ],
            // S04E STEP 3 — FIR 1b batch ledger (the invoice-register half defers
            // to S07, D-11: no batch-time orders to reconcile).
            'enrolment_batches' => [
                'by_status' => DB::table('enrolment_batches')->groupBy('status')
                    ->select('status', DB::raw('count(*) as n'))->get(),
                'row_outcomes' => DB::table('enrolment_batch_rows')->groupBy('status')
                    ->select('status', DB::raw('count(*) as n'))->get(),
                // the FR066 ledger (D-13): failed batches are the actionable exceptions
                'failed' => DB::table('enrolment_batches')->where('status', 'failed')
                    ->select('id', 'school_id', 'programme_id', 'failure_reason', 'created_at')->get(),
            ],
        ]);
    }
}
