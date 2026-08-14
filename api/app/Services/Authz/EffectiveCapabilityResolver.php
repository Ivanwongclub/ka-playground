<?php

namespace App\Services\Authz;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A-3 — resolves a school_admin / teacher actor's EFFECTIVE delegated capabilities from the A-2 grant tables
 * (school_authority_grants baseline + programme_authority_overrides). Two views, by design:
 *
 *   capabilitiesForGuc(User) — the REQUEST-WIDE superset that folds into app.capabilities (ScopeContext).
 *     = (baseline school grants ∪ programme grant-overrides) ∩ A-1 delegable. Withholds are NOT subtracted
 *     here: a school-wide baseline grant is held on every programme lacking a withhold, so a single-programme
 *     withhold never removes the cap request-wide. This mirrors app.school_ids (a request-wide list that RLS
 *     narrows per-row). A-4 narrows per-programme via a scope-join on the overrides.
 *
 *   capabilitiesForProgramme(User, programmeId) — the PER-PROGRAMME truth A-4 will consume (the sole source
 *     of per-programme narrowing). Precedence per capability C:
 *       school-specific override(P,S,C) > all-schools override(P,NULL,C) > baseline grant(S,C)
 *     where mode='withhold' ⇒ NOT held, mode='grant' or baseline ⇒ held. ∩ A-1 delegable.
 *
 * THE DIVERGENCE IS THE DESIGN: a withhold is honored ONLY by capabilitiesForProgramme (→ A-4's scope-join),
 * NEVER by the request-wide GUC. Do not "fix" the GUC to subtract withholds — that would wrongly strip the
 * capability on EVERY programme, not just the withheld one.
 *
 * The caps are PERMISSION keys (teams.approve, …), a different namespace from academy_admin's capability-GROUP
 * names (operations, …) — they share app.capabilities but never collide (groups are dot-free). Reads run under
 * the momentary system context ScopeContext::set() already establishes, so all grant rows are visible.
 */
class EffectiveCapabilityResolver
{
    /** The request-wide superset for app.capabilities (ScopeContext). */
    public function capabilitiesForGuc(User $user): array
    {
        $schoolIds = $this->activeSchoolIds($user);
        if ($schoolIds === []) {
            return []; // no active school → no delegated authority (never accidental)
        }

        $baseline = DB::table('school_authority_grants')
            ->whereIn('school_id', $schoolIds)->whereNull('revoked_at')
            ->pluck('capability');

        $grantOverrides = DB::table('programme_authority_overrides')
            ->where('mode', 'grant')
            ->where(fn ($q) => $q->whereIn('school_id', $schoolIds)->orWhereNull('school_id'))
            ->pluck('capability');

        // Withholds are deliberately NOT read here (request-wide superset — see class docblock).
        return $this->onlyDelegable($baseline->merge($grantOverrides)->unique()->values()->all());
    }

    /** The per-programme effective capability set — the contract A-4 consumes. */
    public function capabilitiesForProgramme(User $user, int $programmeId): array
    {
        $schoolIds = $this->activeSchoolIds($user);
        if ($schoolIds === []) {
            return [];
        }

        // All overrides for this programme in the actor's scope (school-specific or all-schools).
        $overrides = DB::table('programme_authority_overrides')
            ->where('programme_id', $programmeId)
            ->where(fn ($q) => $q->whereIn('school_id', $schoolIds)->orWhereNull('school_id'))
            ->get(['school_id', 'capability', 'mode']);

        // Effective mode per capability. Precedence: a school-specific row (school_id set) beats the
        // all-schools row (school_id NULL). Build all-schools first, then let school-specific overwrite.
        $effective = []; // capability => 'grant' | 'withhold'
        foreach ($overrides as $row) {
            if ($row->school_id === null) {
                $effective[$row->capability] ??= $row->mode; // all-schools: only if no specific already set it
            }
        }
        foreach ($overrides as $row) {
            if ($row->school_id !== null) {
                $effective[$row->capability] = $row->mode; // school-specific always wins
            }
        }

        // Baseline held-set, then apply overrides: grant adds, withhold removes (beats baseline for this programme).
        $held = [];
        foreach (DB::table('school_authority_grants')->whereIn('school_id', $schoolIds)->whereNull('revoked_at')->pluck('capability') as $cap) {
            $held[$cap] = true;
        }
        foreach ($effective as $cap => $mode) {
            if ($mode === 'grant') {
                $held[$cap] = true;
            } else {
                unset($held[$cap]); // withhold beats the baseline for this programme
            }
        }

        return $this->onlyDelegable(array_keys($held));
    }

    /** @return list<int> the actor's active school ids (school_admin_links / teacher_links). Empty for others. */
    private function activeSchoolIds(User $user): array
    {
        return match ($user->role) {
            'school_admin' => DB::table('school_admin_links')
                ->where('school_admin_id', $user->id)->where('status', 'active')->pluck('school_id')->all(),
            'teacher' => DB::table('teacher_links')
                ->where('teacher_id', $user->id)->where('status', 'active')->pluck('school_id')->all(),
            default => [],
        };
    }

    /** Defense-in-depth: only A-1 delegable capabilities may ever appear, even though A-2 already gates writes. */
    private function onlyDelegable(array $caps): array
    {
        $delegable = (array) config('delegable-capabilities.delegable');

        return array_values(array_filter($caps, fn ($c) => in_array($c, $delegable, true)));
    }
}
