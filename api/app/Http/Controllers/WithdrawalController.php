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
        // S-UX3-1: additive names (LEFT — decided_by is null until decided; never drop a row).
        return response()->json(['data' => DB::table('withdrawal_requests as w')
            ->leftJoin('users as s', 's.id', '=', 'w.student_id')
            ->leftJoin('users as rb', 'rb.id', '=', 'w.requested_by')
            ->leftJoin('users as db', 'db.id', '=', 'w.decided_by')
            ->orderBy('w.created_at')
            ->get(['w.id', 'w.enrolment_id', 'w.student_id', 'w.requested_by', 'w.reason', 'w.status', 'w.decided_by', 'w.decided_at',
                's.name as student_name', 'rb.name as requested_by_name', 'db.name as decided_by_name'])]);
    }
}
