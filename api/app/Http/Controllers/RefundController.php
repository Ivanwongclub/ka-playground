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
        return response()->json(['data' => DB::table('refunds')->orderBy('created_at')
            ->get(['id', 'order_id', 'withdrawal_request_id', 'amount_minor', 'currency', 'destination_party', 'status', 'approved_by', 'confirmed_by'])]);
    }

    public function creditNotes(): JsonResponse
    {
        return response()->json(['data' => DB::table('credit_notes')->orderBy('created_at')
            ->get(['id', 'order_id', 'consolidated_invoice_id', 'withdrawal_request_id', 'amount_minor', 'currency', 'reason'])]);
    }
}
