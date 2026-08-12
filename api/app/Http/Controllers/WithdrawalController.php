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
        // R1-F1 (item 2): the terminal decision's CONTEXT beside the decide control — programme name, the
        // decision_reason (own column, unshipped), the refund-window dates (withdrawal_policies, admin-read
        // via $staff) for a display-only policy chip, and the school ENDORSEMENTS (endorser/role/comment).
        // ALL under the caller's RLS (ops passes wr_read/we_read/withdrawal_policies_read) — no wall, no
        // elevation. The endorser NAME rides users_read and degrades to NULL (em-dash) when hidden — the
        // endorsement itself (role + comment) is never dropped.
        $rows = DB::table('withdrawal_requests as w')
            ->leftJoin('users as s', 's.id', '=', 'w.student_id')
            ->leftJoin('users as rb', 'rb.id', '=', 'w.requested_by')
            ->leftJoin('users as db', 'db.id', '=', 'w.decided_by')
            ->leftJoin('enrolments as e', 'e.id', '=', 'w.enrolment_id')
            ->leftJoin('programmes as p', 'p.id', '=', 'e.programme_id')
            ->leftJoin('withdrawal_policies as wp', 'wp.programme_id', '=', 'e.programme_id')
            ->orderBy('w.created_at')
            ->get([
                'w.id', 'w.enrolment_id', 'w.student_id', 'w.requested_by', 'w.reason', 'w.status', 'w.decided_by', 'w.decided_at',
                'w.decision_reason', 'e.programme_id', // R1-F2: +programme_id to linkify the programme name → Programme 360
                's.name as student_name', 'rb.name as requested_by_name', 'db.name as decided_by_name',
                'p.name_en as programme_name_en', 'p.name_tc as programme_name_tc', 'p.name_sc as programme_name_sc',
                'wp.full_refund_before', 'wp.no_refund_after',
                DB::raw("(SELECT json_agg(json_build_object('endorser_name', eu.name, 'endorser_role', we.endorser_role, 'comment', we.comment, 'created_at', we.created_at) ORDER BY we.created_at) FROM withdrawal_endorsements we LEFT JOIN users eu ON eu.id = we.endorser_id WHERE we.withdrawal_request_id = w.id) as endorsements"),
            ]);
        // pgsql json_agg → string; decode to an array (null when the request has no endorsements).
        $rows->each(fn ($r) => $r->endorsements = $r->endorsements ? json_decode($r->endorsements, true) : []);

        return response()->json(['data' => $rows]);
    }
}
