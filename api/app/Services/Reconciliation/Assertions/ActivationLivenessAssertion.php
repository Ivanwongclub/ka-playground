<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S06-1 (R3 / FR012) — activation liveness. Once a programme has started, every
 * confirmed enrolment should be Active; the scheduled activation job runs before
 * this check (02:15 vs 03:00). A confirmed enrolment in a STARTED programme still
 * sitting un-activated means the job silently did not run — a student stuck out
 * of a running programme. This is the activation analogue of the outbox
 * completeness check (a job whose non-execution would otherwise be invisible).
 */
class ActivationLivenessAssertion implements Assertion
{
    public function key(): string
    {
        return 'enrolments.activation_liveness';
    }

    public function proves(): string
    {
        return 'no confirmed enrolment in a started programme is left un-activated — the activation job ran and no student is stuck out of a running programme (R3/FR012)';
    }

    public function cites(): string
    {
        return 'R3 · FR012';
    }

    public function tags(): array
    {
        return ['S06'];
    }

    public function check(): AssertionResult
    {
        $stuck = DB::select(
            "SELECT e.id
             FROM enrolments e
             JOIN wizard_sections ws ON ws.programme_id = e.programme_id AND ws.section_key = 'basics'
             WHERE e.status = 'confirmed'
               AND (ws.data::jsonb->>'starts_on') IS NOT NULL
               AND (ws.data::jsonb->>'starts_on')::date <= now()::date"
        );
        $total = (int) DB::table('enrolments')->where('status', 'active')->count();
        $count = count($stuck);

        return $count > 0
            ? AssertionResult::fail("{$count} confirmed enrolment(s) in a STARTED programme left un-activated — the activation job did not run (R3); run enrolments:run-activations")
            : AssertionResult::pass("{$total} active enrolment(s); no confirmed-in-started enrolment stuck");
    }
}
