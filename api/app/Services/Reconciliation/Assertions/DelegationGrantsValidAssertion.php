<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A-2 — every capability PERSISTED in the delegation grant tables must be ∈ the A-1 delegable catalogue. A
 * never / never_reserved / unknown capability landing in school_authority_grants or
 * programme_authority_overrides (grant OR withhold rows) would mean a dangerous capability reached a
 * school/teacher — exactly what A-1 + AuthorityGrantService forbid. This makes any such row a nightly alarm.
 * Config + DB read only.
 */
class DelegationGrantsValidAssertion implements Assertion
{
    public function key(): string
    {
        return 'authz.delegation_grants_valid';
    }

    public function proves(): string
    {
        return 'every capability in school_authority_grants + programme_authority_overrides is in the A-1 delegable set — no never-capability ever persisted';
    }

    public function cites(): string
    {
        return 'A-1 · A-2 · OD-17';
    }

    public function tags(): array
    {
        return ['A2'];
    }

    public function check(): AssertionResult
    {
        $delegable = (array) config('delegable-capabilities.delegable');

        /** @var Collection<int, string> $offenders */
        $offenders = collect();
        foreach ([
            'school_authority_grants' => DB::table('school_authority_grants')->distinct()->pluck('capability'),
            'programme_authority_overrides' => DB::table('programme_authority_overrides')->distinct()->pluck('capability'),
        ] as $table => $caps) {
            foreach ($caps as $cap) {
                if (! in_array($cap, $delegable, true)) {
                    $offenders->push("{$table}:{$cap}");
                }
            }
        }

        if ($offenders->isNotEmpty()) {
            return AssertionResult::fail('non-delegable capability persisted in a grant table: '.$offenders->implode(', '));
        }

        return AssertionResult::pass('all persisted grant/override capabilities are in the A-1 delegable set');
    }
}
