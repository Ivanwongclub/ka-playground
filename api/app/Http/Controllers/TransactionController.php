<?php

namespace App\Http\Controllers;

use App\Models\TeamTransaction;
use App\Services\Finance\TransactionService;
use App\Services\Uploads\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 2 — team-project transactions + verification (record-only). Authority
 * (team member / reused S05 approver / second-person verifier) is enforced in
 * the service; RLS scopes reads.
 */
class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly UploadService $uploads,
    ) {}

    public function record(Request $request, string $team): JsonResponse
    {
        $v = $request->validate([
            'type' => ['required', 'in:income,expense'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'budget_line_id' => ['nullable', 'uuid'],
            'beneficiary_member_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:200'],
            'occurred_on' => ['required', 'date'],
        ]);
        $txn = $this->transactions->record($team, [
            'type' => $v['type'], 'amount_minor' => (int) $v['amount_minor'],
            'budget_line_id' => $v['budget_line_id'] ?? null, 'beneficiary_member_id' => $v['beneficiary_member_id'] ?? null,
            'description' => $v['description'], 'occurred_on' => $v['occurred_on'],
        ], $request->user());

        return response()->json(['transaction_id' => $txn->id, 'status' => $txn->status], 201);
    }

    /** Upload a receipt (BI-10 scanned) and attach it — the evidence-before-submit step. */
    public function attachEvidence(Request $request, string $transaction): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $upload = $this->uploads->intake($request->file('file'), 'evidence', $request->user());
        $this->transactions->attachEvidence($transaction, $upload->id, $request->user());

        return response()->json(['upload_id' => $upload->id, 'status' => $this->status($transaction)]);
    }

    public function submit(Request $request, string $transaction): JsonResponse
    {
        $this->transactions->submit($transaction, $request->user());

        return response()->json(['transaction_id' => $transaction, 'status' => $this->status($transaction)]);
    }

    public function approve(Request $request, string $transaction): JsonResponse
    {
        $this->transactions->approve($transaction, $request->user(), (bool) $request->boolean('over_budget_acknowledged'));

        return response()->json(['transaction_id' => $transaction, 'status' => $this->status($transaction)]);
    }

    public function reject(Request $request, string $transaction): JsonResponse
    {
        $this->transactions->reject($transaction, $request->user(), $request->input('notes'));

        return response()->json(['transaction_id' => $transaction, 'status' => $this->status($transaction)]);
    }

    public function verify(Request $request, string $transaction): JsonResponse
    {
        $this->transactions->verify($transaction, $request->user());

        return response()->json(['transaction_id' => $transaction, 'status' => $this->status($transaction)]);
    }

    public function index(Request $request, string $team): JsonResponse
    {
        return response()->json([
            'transactions' => TeamTransaction::query()->where('team_id', $team)->orderByDesc('occurred_on')
                ->get(['id', 'type', 'amount_minor', 'currency', 'budget_line_id', 'beneficiary_member_id', 'description', 'occurred_on', 'status', 'recorded_by', 'verified_by', 'over_budget_acknowledged']),
        ]);
    }

    private function status(string $txnId): ?string
    {
        return DB::table('team_transactions')->where('id', $txnId)->value('status');
    }
}
