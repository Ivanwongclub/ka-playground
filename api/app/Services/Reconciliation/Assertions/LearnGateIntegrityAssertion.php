<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S06-7 (OD-12) — every passed Learn gate met the team threshold AT PASS TIME.
 * Judged by the IMMUTABLE eligibility snapshot recorded on the `stage_gate.passed`
 * audit (qualifying / active_members / team_gate_pass_pct), NOT a live recompute —
 * so a team whose attendance or roster legitimately changed after the gate passed
 * does not false-red. Same snapshot-time discipline as consent_complete_at_confirm.
 * A Learn gate pass with no snapshot, or a snapshot below threshold, is the red.
 */
class LearnGateIntegrityAssertion implements Assertion
{
    public function key(): string
    {
        return 'teams.learn_gate_integrity';
    }

    public function proves(): string
    {
        return 'every passed Learn gate carries an eligibility snapshot that met the team threshold at pass time (qualifying% ≥ team_gate_pass_pct) — no Learn gate passed on an ineligible team (OD-12, judged snapshot-time)';
    }

    public function cites(): string
    {
        return 'OD-12';
    }

    public function tags(): array
    {
        return ['S06'];
    }

    public function check(): AssertionResult
    {
        $learnPasses = DB::table('audit_events')
            ->where('action', 'stage_gate.passed')
            ->whereRaw("payload_after->>'stage' = 'Learn'")
            ->get(['event_id', 'payload_after']);

        $bad = [];
        foreach ($learnPasses as $p) {
            $snap = json_decode((string) $p->payload_after, true)['learn_eligibility'] ?? null;
            if ($snap === null
                || ! isset($snap['qualifying'], $snap['active_members'], $snap['team_gate_pass_pct'])
                || (int) $snap['active_members'] <= 0
                || (int) $snap['qualifying'] * 100 < (int) $snap['team_gate_pass_pct'] * (int) $snap['active_members']) {
                $bad[] = $p->event_id;
            }
        }
        $total = $learnPasses->count();

        return $bad !== []
            ? AssertionResult::fail(count($bad).' Learn gate pass(es) with no eligibility snapshot or below threshold at pass time (OD-12)')
            : AssertionResult::pass("{$total} Learn gate pass(es) checked".($total === 0 ? ' (vacuous)' : ', all met the threshold at pass time'));
    }
}
