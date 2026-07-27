<?php

namespace App\Http\Controllers;

use App\Services\Money\ManualPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManualPaymentController extends Controller
{
    public function __construct(private readonly ManualPaymentService $payments) {}

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'note' => ['sometimes', 'string'],
            'evidence' => ['required', 'array', 'min:1'],
            'evidence.*' => ['file'],
        ]);
        $payment = $this->payments->record(
            $data['order_id'], (int) $data['amount_minor'], $data['currency'],
            $request->file('evidence', []), $data['note'] ?? null, $request->user(),
        );

        return response()->json(['id' => $payment->id, 'status' => $payment->status], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $this->payments->confirm($id, $request->user());

        return response()->json(['status' => 'confirmed']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5']])['reason'];
        $this->payments->reject($id, $reason, $request->user());

        return response()->json(['status' => 'rejected']);
    }

    /** RLS-shaped within the finance.view gate (Member 403 preserved from S01). */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('payments')->orderBy('created_at')
            ->get(['id', 'order_id', 'origin', 'provider', 'amount_minor', 'currency', 'via_link', 'status', 'recorded_by', 'confirmed_by', 'paid_at'])]);
    }
}
