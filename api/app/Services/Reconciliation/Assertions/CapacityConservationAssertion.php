<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/**
 * S05-6 — capacity conservation (OD-31/32). The DB CHECK (claimed ≤ capacity)
 * guards the COUNTER; this independently guards REALITY: the seats actually held
 * by confirmed teams (their live members) must not exceed the programme capacity.
 * It is the backstop against an overbook the Team Formation transaction somehow let through
 * — so a planted claimed > capacity is exactly the red we want to catch.
 */
class CapacityConservationAssertion implements Assertion
{
    public function key(): string
    {
        return 'capacity.conservation';
    }

    public function proves(): string
    {
        return 'the seats held by confirmed teams never exceed programme capacity (Σ confirmed-team members ≤ capacity, per programme) — no overbook slips past the seat claim';
    }

    public function cites(): string
    {
        return 'OD-31 · OD-32 · BI-3';
    }

    public function tags(): array
    {
        return ['S05'];
    }

    public function check(): AssertionResult
    {
        $rows = DB::select(
            "SELECT pc.programme_id, pc.capacity,
                    COUNT(tm.id) FILTER (WHERE tm.status IN ('active','suspended')) AS held
             FROM programme_capacity pc
             LEFT JOIN teams t ON t.programme_id = pc.programme_id AND t.status = 'confirmed'
             LEFT JOIN team_members tm ON tm.team_id = t.id
             GROUP BY pc.programme_id, pc.capacity
             HAVING COUNT(tm.id) FILTER (WHERE tm.status IN ('active','suspended')) > pc.capacity"
        );
        $total = (int) DB::table('programme_capacity')->count();

        if ($rows !== []) {
            $detail = implode(', ', array_map(fn ($r) => "programme {$r->programme_id}: {$r->held} held > {$r->capacity} capacity", $rows));
            return AssertionResult::fail("OVERBOOK — {$detail} (OD-31/32)");
        }

        return AssertionResult::pass("{$total} programme capacity counter(s) checked".($total === 0 ? ' (vacuous)' : ', all conserve capacity'));
    }
}
