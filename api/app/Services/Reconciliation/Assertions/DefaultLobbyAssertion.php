<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;

/** OD-13b: every programme that has lobbies has exactly one default. */
class DefaultLobbyAssertion implements Assertion
{
    public function key(): string
    {
        return 'teams.one_default_lobby';
    }

    public function proves(): string
    {
        return 'every programme with team_categories has exactly one default lobby';
    }

    public function cites(): string
    {
        return 'OD-13b · TEAM-CATEGORIES.md';
    }

    public function tags(): array
    {
        return ['S02B'];
    }

    public function check(): AssertionResult
    {
        $bad = DB::table('team_categories')
            ->selectRaw('programme_id, count(*) FILTER (WHERE is_default) AS defaults')
            ->whereNull('retired_at')
            ->groupBy('programme_id')
            ->havingRaw('count(*) FILTER (WHERE is_default) <> 1')
            ->get();

        return $bad->isEmpty()
            ? AssertionResult::pass('every lobby-carrying programme has exactly one default')
            : AssertionResult::fail($bad->map(fn ($r) => "programme {$r->programme_id}: {$r->defaults} defaults")->implode('; '));
    }
}
