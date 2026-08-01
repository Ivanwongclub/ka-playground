<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S07 STEP 3 (OD-4) — charity funds are never distributed to team members. No
 * `charity` project has an expense transaction naming a team member as
 * beneficiary. TransactionService refuses this at record time; this is the
 * path-independent belt — it catches any such row however it arose.
 */
class CharityNoDistributionAssertion implements Assertion
{
    public function key(): string
    {
        return 'finance.charity_no_distribution';
    }

    public function proves(): string
    {
        return 'no charity project has an expense distributing funds to a team member (beneficiary_member_id) — charity money is never taken by the members, whatever the write path (OD-4)';
    }

    public function cites(): string
    {
        return 'OD-4 · FR057 · S07 STEP 3';
    }

    public function tags(): array
    {
        return ['S07'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            "SELECT t.id FROM team_transactions t
             JOIN team_fundraising f ON f.team_id = t.team_id
             WHERE f.project_type = 'charity'
               AND t.type = 'expense'
               AND t.beneficiary_member_id IS NOT NULL"
        );

        if ($bad !== []) {
            return AssertionResult::fail(
                count($bad).' charity-project expense(s) name a member beneficiary — a distribution of charity funds to a member (OD-4 breach)'
            );
        }

        return AssertionResult::pass('no charity project distributes funds to a team member (OD-4 holds)');
    }
}
