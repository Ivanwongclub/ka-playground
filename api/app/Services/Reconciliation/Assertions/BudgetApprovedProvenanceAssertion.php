<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 1 (FR061) — the link-activation pattern for budgets. Every budget that
 * reached an approved state (approved → active → closed) carries a
 * `team_budget.approved` audit event — an approving decision. A budget that is
 * active without that audit has no recorded approval, however it got there, and
 * this reds. Path-independent (it scans the state, not the write path).
 */
class BudgetApprovedProvenanceAssertion implements Assertion
{
    private const APPROVED_STATES = ['approved', 'active', 'closed'];

    public function key(): string
    {
        return 'finance.budget_approved_provenance';
    }

    public function proves(): string
    {
        return 'every team budget that reached approved/active/closed carries a team_budget.approved audit — no budget is operative (Active, gating the Plan stage) without a recorded approving decision';
    }

    public function cites(): string
    {
        return 'FR061 · Spec §P1 · S07 STEP 1';
    }

    public function tags(): array
    {
        return ['S07'];
    }

    public function check(): AssertionResult
    {
        $states = "'".implode("','", self::APPROVED_STATES)."'";
        $orphans = DB::select(
            "SELECT b.id FROM team_budgets b
             WHERE b.status IN ({$states})
               AND NOT EXISTS (
                   SELECT 1 FROM audit_events ae
                   WHERE ae.entity_type = 'team_budget' AND ae.entity_id = b.id::text
                     AND ae.action = 'team_budget.approved'
               )"
        );

        if ($orphans !== []) {
            return AssertionResult::fail(
                count($orphans).' approved/active budget(s) with NO team_budget.approved audit — an operative budget without a recorded approval'
            );
        }

        return AssertionResult::pass('every approved/active budget carries an approving audit');
    }
}
