<?php

namespace App\Http\Controllers;

use App\Services\Enrolments\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function store(Request $request, string $enrolmentId): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5']])['reason'];
        $row = $this->withdrawals->request($enrolmentId, $reason, $request->user());

        return response()->json(['id' => $row->id, 'status' => $row->status], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->withdrawals->cancel($id, $request->user());

        return response()->json(['status' => 'cancelled']);
    }

    public function endorse(Request $request, string $id): JsonResponse
    {
        $comment = $request->validate(['comment' => ['required', 'string', 'min:5']])['comment'];
        $this->withdrawals->endorse($id, $comment, $request->user());

        return response()->json(['endorsed' => true]);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['sometimes', 'string'],
        ]);
        $this->withdrawals->decide($id, (bool) $data['approve'], $data['reason'] ?? null, $request->user());

        return response()->json(['decided' => true]);
    }

    /** RLS-shaped list. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('withdrawal_requests')->orderBy('created_at')
            ->get(['id', 'enrolment_id', 'student_id', 'requested_by', 'reason', 'status', 'decided_by', 'decided_at'])]);
    }
}
