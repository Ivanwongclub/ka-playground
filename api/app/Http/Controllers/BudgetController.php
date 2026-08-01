<?php

namespace App\Http\Controllers;

use App\Models\TeamBudget;
use App\Services\Finance\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 1 — team-project budget lifecycle (record-only). Authority (team
 * membership / reused S05 approver) is enforced in the service; RLS scopes reads.
 */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function create(Request $request, string $team): JsonResponse
    {
        $budget = $this->budgets->createDraft($team, $request->user());

        return response()->json(['budget_id' => $budget->id, 'status' => $budget->status], 201);
    }

    public function addLine(Request $request, string $budget): JsonResponse
    {
        $v = $request->validate([
            'category' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'planned_amount_minor' => ['required', 'integer', 'min:0'],
        ]);
        $id = $this->budgets->addLine($budget, $v['category'], $v['name'], (int) $v['planned_amount_minor'], $request->user());

        return response()->json(['line_id' => $id], 201);
    }

    public function submit(Request $request, string $budget): JsonResponse
    {
        $this->budgets->submit($budget, $request->user());

        return $this->state($budget);
    }

    public function approve(Request $request, string $budget): JsonResponse
    {
        $this->budgets->approve($budget, $request->user());

        return $this->state($budget);
    }

    public function requestChanges(Request $request, string $budget): JsonResponse
    {
        $this->budgets->requestChanges($budget, $request->user(), $request->input('notes'));

        return $this->state($budget);
    }

    public function revise(Request $request, string $budget): JsonResponse
    {
        $this->budgets->revise($budget, $request->user());

        return $this->state($budget);
    }

    public function close(Request $request, string $budget): JsonResponse
    {
        $this->budgets->close($budget, $request->user());

        return $this->state($budget);
    }

    /** The team's budget + lines (RLS-scoped: members / linked teacher / lobby admin / ops). */
    public function show(Request $request, string $team): JsonResponse
    {
        $budget = TeamBudget::query()->where('team_id', $team)->orderByDesc('created_at')->first();
        if ($budget === null) {
            return response()->json(['message' => 'No budget for this team'], 404);
        }

        return response()->json([
            'budget_id' => $budget->id,
            'status' => $budget->status,
            'currency' => $budget->currency,
            'lines' => $budget->lines()->get(['id', 'category', 'name', 'planned_amount_minor', 'currency']),
            'total_planned_minor' => (int) DB::table('budget_lines')->where('budget_id', $budget->id)->sum('planned_amount_minor'),
        ]);
    }

    private function state(string $budgetId): JsonResponse
    {
        return response()->json(['budget_id' => $budgetId, 'status' => DB::table('team_budgets')->where('id', $budgetId)->value('status')]);
    }
}
