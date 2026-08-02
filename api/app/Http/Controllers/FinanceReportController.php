<?php

namespace App\Http\Controllers;

use App\Models\TeamBudget;
use App\Models\TeamFundraising;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 4 — the Team Finance Verification Report (audit element). Budget vs
 * actual vs verified per team, the team P&L (Σ verified income − Σ verified
 * expense; no cash position — record-only), unverified-entry aging, and the
 * approval chain per transaction with a drill-down handle to the scanned
 * evidence. RLS scopes it to the team (members · linked teacher · lobby admin ·
 * ops/audit); another team sees zero.
 */
class FinanceReportController extends Controller
{
    public function show(Request $request, string $team): JsonResponse
    {
        $budget = TeamBudget::query()->where('team_id', $team)->orderByDesc('created_at')->first();
        $fundraising = TeamFundraising::query()->where('team_id', $team)->first();

        // per-line: planned vs actual (recorded+verified expense) vs verified (verified only)
        $lines = [];
        if ($budget !== null) {
            foreach ($budget->lines()->get() as $line) {
                $actual = (int) DB::table('team_transactions')->where('budget_line_id', $line->id)->where('type', 'expense')
                    ->whereIn('status', ['recorded', 'verified'])->sum('amount_minor');
                $verified = (int) DB::table('team_transactions')->where('budget_line_id', $line->id)->where('type', 'expense')
                    ->where('status', 'verified')->sum('amount_minor');
                $lines[] = [
                    'category' => $line->category, 'name' => $line->name,
                    'planned_minor' => (int) $line->planned_amount_minor,
                    'actual_minor' => $actual, 'verified_minor' => $verified,
                    'over_budget' => $actual > (int) $line->planned_amount_minor,
                ];
            }
        }

        // team P&L — verified only (offline reality confirmed); no cash position.
        $income = (int) DB::table('team_transactions')->where('team_id', $team)->where('type', 'income')->where('status', 'verified')->sum('amount_minor');
        $expense = (int) DB::table('team_transactions')->where('team_id', $team)->where('type', 'expense')->where('status', 'verified')->sum('amount_minor');

        return response()->json([
            'team_id' => $team,
            'project' => $fundraising === null ? null : [
                'project_type' => $fundraising->project_type,
                'funding_target_minor' => (int) $fundraising->funding_target_minor,
                'raised_verified_minor' => $income,
            ],
            'budget' => $budget === null ? null : ['status' => $budget->status, 'lines' => $lines],
            'pnl' => ['verified_income_minor' => $income, 'verified_expense_minor' => $expense, 'net_minor' => $income - $expense],
            // unverified-entry aging: recorded but not yet verified (days since recorded)
            'unverified_aging' => DB::table('team_transactions')->where('team_id', $team)->where('status', 'recorded')
                ->orderBy('recorded_at')
                ->get(['id', 'type', 'amount_minor', 'description', 'recorded_at'])
                ->map(fn ($t) => ['id' => $t->id, 'type' => $t->type, 'amount_minor' => (int) $t->amount_minor, 'description' => $t->description,
                    'age_days' => $t->recorded_at ? (int) now()->diffInDays($t->recorded_at, absolute: true) : null]),
            // approval chain + evidence drill-down handle per transaction
            // S-UX2b: additive recorder/verifier names via LEFT JOINs (never drop a row). NOTE (for the
            // S-UX3 consumer): each name is gated by users_read, which currently admits self + ops/audit
            // admins only — team co-members are mutually invisible in the users table, so for a typical
            // student-member caller these resolve to their own name or NULL. Full co-member name display
            // needs a users_read branch for active team co-membership: a scoped RLS change, its own ruling.
            'transactions' => DB::table('team_transactions as t')
                ->leftJoin('users as rb', 'rb.id', '=', 't.recorded_by')
                ->leftJoin('users as vb', 'vb.id', '=', 't.verified_by')
                ->where('t.team_id', $team)->orderByDesc('t.occurred_on')
                ->get(['t.id', 't.type', 't.amount_minor', 't.description', 't.status', 't.recorded_by', 't.verified_by',
                    't.evidence_upload_id', 't.over_budget_acknowledged', 'rb.name as recorded_by_name', 'vb.name as verified_by_name']),
        ]);
    }
}
