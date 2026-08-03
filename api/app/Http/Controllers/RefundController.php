<?php

namespace App\Http\Controllers;

use App\Services\Money\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function approve(Request $request, string $id): JsonResponse
    {
        $note = $request->validate(['evidence_note' => ['required', 'string', 'min:3']])['evidence_note'];
        $this->refunds->approve($id, $note, $request->user());

        return response()->json(['status' => 'approved']);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $this->refunds->confirm($id, $request->user());

        return response()->json(['status' => 'confirmed']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5']])['reason'];
        $this->refunds->reject($id, $reason, $request->user());

        return response()->json(['status' => 'rejected']);
    }

    public function index(): JsonResponse
    {
        // S-UX3-2: additive names — approved_by/confirmed_by resolve for finance staff (BI-9 legibility).
        return response()->json(['data' => DB::table('refunds as r')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('users as s', 's.id', '=', 'o.student_id')
            ->leftJoin('users as ab', 'ab.id', '=', 'r.approved_by')
            ->leftJoin('users as cb', 'cb.id', '=', 'r.confirmed_by')
            ->orderBy('r.created_at')
            ->get(['r.id', 'r.order_id', 'r.withdrawal_request_id', 'r.amount_minor', 'r.currency', 'r.destination_party', 'r.status', 'r.approved_by', 'r.confirmed_by',
                'ab.name as approved_by_name', 'cb.name as confirmed_by_name', 's.name as student_name'])]);
    }

    public function creditNotes(): JsonResponse
    {
        return response()->json(['data' => DB::table('credit_notes')->orderBy('created_at')
            ->get(['id', 'order_id', 'consolidated_invoice_id', 'withdrawal_request_id', 'amount_minor', 'currency', 'reason'])]);
    }
}
