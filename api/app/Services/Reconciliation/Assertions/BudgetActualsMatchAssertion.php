<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 4 (FR061 · spec:1776) — a budget's actual is the sum of the recorded
 * transactions against its lines, and that identity is leak-free: every recorded
 * (or verified) transaction carrying a budget_line_id references a line that
 * belongs to the SAME team and to a budget that was actually APPROVED (active or
 * closed). So the P&L's per-line actual can only aggregate spend against a
 * genuinely approved budget — no orphan, cross-team, or unapproved-budget spend
 * silently swells a team's actuals.
 */
class BudgetActualsMatchAssertion implements Assertion
{
    public function key(): string
    {
        return 'finance.budget_actuals_match';
    }

    public function proves(): string
    {
        return "every recorded transaction against a budget line belongs to that line's own team and an approved (active/closed) budget — a budget's actual reconciles to approved spend only, never orphan or cross-team";
    }

    public function cites(): string
    {
        return 'FR061 · spec:1776 · S07 STEP 4';
    }

    public function tags(): array
    {
        return ['S07'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            "SELECT t.id FROM team_transactions t
             JOIN budget_lines bl ON bl.id = t.budget_line_id
             LEFT JOIN team_budgets b ON b.id = bl.budget_id
             WHERE t.status IN ('recorded','verified')
               AND (bl.team_id <> t.team_id OR b.status IS NULL OR b.status NOT IN ('active','closed'))"
        );

        if ($bad !== []) {
            return AssertionResult::fail(
                count($bad).' recorded transaction(s) reference a budget line of another team or an unapproved budget — a budget actual that would not reconcile to approved spend'
            );
        }

        return AssertionResult::pass('every recorded budget-line transaction reconciles to its own team\'s approved budget');
    }
}
