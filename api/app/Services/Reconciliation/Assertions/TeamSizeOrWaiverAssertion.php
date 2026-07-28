<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 (OD-40) — every CONFIRMED team either meets its size rule or carries a
 * written waiver. Members are counted as active+suspended (a lapse suspension is
 * still a member composition-wise, so a non-payment lapse alone never reds this —
 * it opens a below_min exception that resolves via assign/waive/dissolve). A
 * confirmed team below the minimum with no waiver_reason is the violation.
 */
class TeamSizeOrWaiverAssertion implements Assertion
{
    public function key(): string
    {
        return 'teams.size_or_waiver';
    }

    public function proves(): string
    {
        return 'every confirmed team meets its minimum size (counting active+suspended members) OR carries a written under-strength waiver (OD-40)';
    }

    public function cites(): string
    {
        return 'OD-40 · OD-37';
    }

    public function tags(): array
    {
        return ['S05'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::select(
            "SELECT t.id, t.programme_id,
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id AND tm.status IN ('active','suspended')) AS members,
                    COALESCE((SELECT (ws.data::jsonb->>'min_team_size')::int FROM wizard_sections ws
                              WHERE ws.programme_id = t.programme_id AND ws.section_key = 'team_rules'), 1) AS min_size
             FROM teams t
             WHERE t.status = 'confirmed' AND t.waiver_reason IS NULL"
        );
        $violations = array_filter($bad, fn ($r) => (int) $r->members < (int) $r->min_size);
        $total = (int) DB::table('teams')->where('status', 'confirmed')->count();

        if ($violations !== []) {
            $detail = implode(', ', array_map(fn ($r) => "team {$r->id}: {$r->members}/{$r->min_size}", $violations));
            return AssertionResult::fail(count($violations)." confirmed team(s) below minimum with no waiver — {$detail} (OD-40)");
        }

        return AssertionResult::pass("{$total} confirmed team(s) checked".($total === 0 ? ' (vacuous)' : ', all meet size or carry a waiver'));
    }
}
