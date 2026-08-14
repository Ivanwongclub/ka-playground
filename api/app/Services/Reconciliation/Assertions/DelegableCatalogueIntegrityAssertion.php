<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Collection;

/**
 * A-1 — the delegable-capability catalogue is the delegation SAFETY SPINE. Before A-2 (grant tables) and A-4
 * (policy arms) build delegation, this catalogue fixes which permissions may reach a school/teacher edge
 * operator and which never can. This assertion makes any drift a nightly alarm:
 *   - no orphan/typo: every classified key exists in permission-matrix 'permissions';
 *   - complete: delegable ∪ never == the full permission set (nothing unclassified);
 *   - disjoint: no permission is in BOTH sets;
 *   - the hard-NEVER spine holds: {consent.sign, finance.confirm, capabilities.grant, configuration.manage} ⊆ never;
 *   - the reserved formed-team marker is present (a future edit can't silently drop platform-only membership mutation).
 * Config-only (no DB). A red here means a dangerous capability could become delegable — a stop, not a skip.
 */
class DelegableCatalogueIntegrityAssertion implements Assertion
{
    /** The hard-NEVER minimum — immovable, whatever else the catalogue says. */
    private const HARD_NEVER = ['consent.sign', 'finance.confirm', 'capabilities.grant', 'configuration.manage'];

    public function key(): string
    {
        return 'authz.delegable_catalogue_integrity';
    }

    public function proves(): string
    {
        return 'every permission is classified delegable-or-never, the hard-never spine holds, and the formed-team marker is present';
    }

    public function cites(): string
    {
        return 'A-1 · BI-6 · BI-9 · OD-17';
    }

    public function tags(): array
    {
        return ['A1'];
    }

    public function check(): AssertionResult
    {
        /** @var Collection<int, string> $permissions */
        $permissions = collect(config('permission-matrix.permissions'));
        /** @var Collection<int, string> $delegable */
        $delegable = collect(config('delegable-capabilities.delegable'));
        /** @var Collection<int, string> $never */
        $never = collect(config('delegable-capabilities.never'));
        $reserved = (array) config('delegable-capabilities.never_reserved');

        // 1 — no orphan/typo: every classified key is a real matrix permission.
        $orphans = $delegable->merge($never)->reject(fn ($k) => $permissions->contains($k))->unique();
        if ($orphans->isNotEmpty()) {
            return AssertionResult::fail('classified key(s) absent from permission-matrix: '.$orphans->implode(', '));
        }

        // 2 — disjoint: no permission classified BOTH ways.
        $both = $delegable->intersect($never)->unique();
        if ($both->isNotEmpty()) {
            return AssertionResult::fail('permission(s) in BOTH delegable and never: '.$both->implode(', '));
        }

        // 3 — complete: every permission is classified exactly once.
        $classified = $delegable->merge($never)->unique();
        $unclassified = $permissions->reject(fn ($p) => $classified->contains($p));
        if ($unclassified->isNotEmpty()) {
            return AssertionResult::fail('unclassified permission(s): '.$unclassified->implode(', '));
        }
        if ($classified->count() !== $permissions->count()) {
            return AssertionResult::fail("classification count {$classified->count()} != permission count {$permissions->count()} (duplicate or stray key)");
        }

        // 4 — the hard-NEVER spine is intact.
        $missingSpine = collect(self::HARD_NEVER)->reject(fn ($k) => $never->contains($k));
        if ($missingSpine->isNotEmpty()) {
            return AssertionResult::fail('hard-never key(s) not in the never set: '.$missingSpine->implode(', '));
        }

        // 5 — the reserved formed-team marker is present (platform-only membership mutation, born never-delegable).
        if (($reserved['teams.formed_membership_mutation'] ?? null) !== 'platform_only') {
            return AssertionResult::fail("never_reserved missing marker teams.formed_membership_mutation => 'platform_only'");
        }

        return AssertionResult::pass(
            "all {$permissions->count()} permissions classified ({$delegable->count()} delegable, {$never->count()} never); hard-never spine intact; formed-team marker present"
        );
    }
}
