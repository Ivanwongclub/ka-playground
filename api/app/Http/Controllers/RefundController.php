<?php

namespace App\Http\Controllers;

use App\Services\Authz\ScopeContext;
use App\Services\Money\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController extends Controller
{
    // R1-F1 (item 4): the governed reason for the refund-origin elevation — MUST match scope-elevations.php.
    private const ORIGIN_REASON = 'Refund origin attribution (R1-F1 item 4): resolve the originating withdrawal\'s DISPLAY fields only — reason, requested_by name, decided_by name — for the withdrawal_request_ids ALREADY in the caller-RLS refund payload, so a finance officer (finance is NOT in wr_read) sees why the refund exists. Display fields ONLY — no student/guardian id, no enrolment, nothing beyond these three. Resolved AFTER the caller-RLS refund fetch, one narrow call.';

    public function __construct(private readonly RefundService $refunds, private readonly ScopeContext $scope) {}

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
        $rows = DB::table('refunds as r')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('users as s', 's.id', '=', 'o.student_id')
            ->leftJoin('users as ab', 'ab.id', '=', 'r.approved_by')
            ->leftJoin('users as cb', 'cb.id', '=', 'r.confirmed_by')
            ->orderBy('r.created_at')
            ->get(['r.id', 'r.order_id', 'r.withdrawal_request_id', 'r.amount_minor', 'r.currency', 'r.destination_party', 'r.status', 'r.approved_by', 'r.confirmed_by',
                'ab.name as approved_by_name', 'cb.name as confirmed_by_name', 's.name as student_name']);

        // R1-F1 (item 4): the ORIGIN in view. A refund carries withdrawal_request_id, but finance is NOT in
        // wr_read — the join would NULL. Resolve DISPLAY fields only (reason + requested_by/decided_by names)
        // via the governed elevation, for the withdrawal_request_ids ALREADY in the caller-RLS refund payload.
        $wrIds = $rows->pluck('withdrawal_request_id')->filter()->unique()->all();
        $origins = $wrIds === [] ? [] : $this->scope->asSystem(self::ORIGIN_REASON, fn (): array => DB::table('withdrawal_requests as w')
            ->leftJoin('users as rb', 'rb.id', '=', 'w.requested_by')
            ->leftJoin('users as db', 'db.id', '=', 'w.decided_by')
            ->whereIn('w.id', $wrIds)
            ->get(['w.id', 'w.reason', 'rb.name as requested_by_name', 'db.name as decided_by_name'])
            ->keyBy('id')
            ->map(fn ($o) => ['reason' => $o->reason, 'requested_by_name' => $o->requested_by_name, 'decided_by_name' => $o->decided_by_name])
            ->all());
        $rows->each(function ($r) use ($origins) {
            $o = $origins[$r->withdrawal_request_id] ?? null;
            $r->withdrawal_reason = $o['reason'] ?? null;
            $r->withdrawal_requested_by_name = $o['requested_by_name'] ?? null;
            $r->withdrawal_decided_by_name = $o['decided_by_name'] ?? null;
        });

        return response()->json(['data' => $rows]);
    }

    public function creditNotes(): JsonResponse
    {
        return response()->json(['data' => DB::table('credit_notes')->orderBy('created_at')
            ->get(['id', 'order_id', 'consolidated_invoice_id', 'withdrawal_request_id', 'amount_minor', 'currency', 'reason'])]);
    }
}
