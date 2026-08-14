<?php

namespace App\Services\Authz;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A-2 — the ONLY writer of the delegation grant tables (school_authority_grants + programme_authority_overrides).
 * Every write:
 *   - validates the capability ∈ the A-1 delegable catalogue (never/never_reserved/unknown are REFUSED);
 *   - runs in system context via ScopeContext::asSystem — RLS insert/update is system-only, so no edge operator
 *     (school/teacher) can write these tables directly (they ARE the delegation config; A-1 marks
 *     capabilities.grant + configuration.manage never-delegable);
 *   - is audited (actor + entity + before/after), like every other authz service.
 * The asSystem reasons are constants referenced verbatim by config/scope-elevations.php (asSystem requires a
 * byte-identical match).
 */
class AuthorityGrantService
{
    public const REASON_GRANT = 'A-2 school authority grant: writing the delegation map is platform-exclusive — A-1 marks capabilities.grant and configuration.manage never-delegable, so no edge operator (school/teacher) may write these tables. System-context INSERT to school_authority_grants; the capability is validated ∈ the A-1 delegable catalogue before the write; audited to the actor.';

    public const REASON_REVOKE = 'A-2 school authority revoke: revoking a school\'s delegated capability is platform-exclusive (same A-1 rationale — the delegation map is never edge-writable). System-context UPDATE sets revoked_by/revoked_at on the active grant; audited to the actor.';

    public const REASON_OVERRIDE = 'A-2 programme authority override: a per-programme grant/withhold of a delegable capability is platform-exclusive (A-1: the delegation map is never edge-writable). System-context UPSERT to programme_authority_overrides (current-state, one row per target); the capability is validated ∈ the A-1 delegable catalogue; audited to the actor.';

    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
    ) {}

    /** The A-1 catalogue is the gate: only a delegable capability may reach a school/teacher. */
    private function assertDelegable(string $capability): void
    {
        $delegable = (array) config('delegable-capabilities.delegable');
        if (! in_array($capability, $delegable, true)) {
            throw new InvalidArgumentException(
                "capability '{$capability}' is NOT delegable (A-1 catalogue) — only delegable capabilities may be granted to a school/teacher; never / never_reserved / unknown keys are refused."
            );
        }
    }

    public function grant(User $actor, int $schoolId, string $capability): void
    {
        $this->assertDelegable($capability);

        $this->scope->asSystem(self::REASON_GRANT, function () use ($actor, $schoolId, $capability) {
            $active = DB::table('school_authority_grants')
                ->where('school_id', $schoolId)->where('capability', $capability)
                ->whereNull('revoked_at')->exists();
            if ($active) {
                return; // idempotent — one active grant per (school, capability); no duplicate audit
            }
            DB::transaction(function () use ($actor, $schoolId, $capability) {
                DB::table('school_authority_grants')->insert([
                    'id' => (string) Str::uuid7(),
                    'school_id' => $schoolId,
                    'capability' => $capability,
                    'granted_by' => $actor->id,
                    'granted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit->record(
                    entityType: 'school',
                    entityId: (string) $schoolId,
                    action: 'authority_grant.granted',
                    toState: $capability,
                    payloadAfter: ['school_id' => $schoolId, 'capability' => $capability, 'granted_by' => $actor->id],
                    actor: $actor,
                );
            });
        });
    }

    public function revoke(User $actor, int $schoolId, string $capability): void
    {
        $this->scope->asSystem(self::REASON_REVOKE, function () use ($actor, $schoolId, $capability) {
            $updated = DB::table('school_authority_grants')
                ->where('school_id', $schoolId)->where('capability', $capability)
                ->whereNull('revoked_at')
                ->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'updated_at' => now()]);
            if ($updated === 0) {
                return; // nothing active to revoke
            }
            $this->audit->record(
                entityType: 'school',
                entityId: (string) $schoolId,
                action: 'authority_grant.revoked',
                fromState: $capability,
                payloadAfter: ['school_id' => $schoolId, 'capability' => $capability, 'revoked_by' => $actor->id],
                actor: $actor,
            );
        });
    }

    public function setOverride(User $actor, int $programmeId, ?int $schoolId, string $capability, string $mode): void
    {
        $this->assertDelegable($capability);
        if (! in_array($mode, ['grant', 'withhold'], true)) {
            throw new InvalidArgumentException("override mode '{$mode}' is invalid — expected 'grant' or 'withhold'");
        }

        $this->scope->asSystem(self::REASON_OVERRIDE, function () use ($actor, $programmeId, $schoolId, $capability, $mode) {
            DB::transaction(function () use ($actor, $programmeId, $schoolId, $capability, $mode) {
                $q = DB::table('programme_authority_overrides')
                    ->where('programme_id', $programmeId)->where('capability', $capability);
                $schoolId === null ? $q->whereNull('school_id') : $q->where('school_id', $schoolId);
                $existing = $q->first();

                $before = $existing ? ['mode' => $existing->mode, 'set_by' => (int) $existing->set_by] : null;
                if ($existing) {
                    $q->update(['mode' => $mode, 'set_by' => $actor->id, 'set_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('programme_authority_overrides')->insert([
                        'id' => (string) Str::uuid7(),
                        'programme_id' => $programmeId,
                        'school_id' => $schoolId,
                        'capability' => $capability,
                        'mode' => $mode,
                        'set_by' => $actor->id,
                        'set_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $this->audit->record(
                    entityType: 'programme',
                    entityId: (string) $programmeId,
                    action: 'authority_override.set',
                    toState: "{$mode}:{$capability}",
                    payloadBefore: $before,
                    payloadAfter: ['programme_id' => $programmeId, 'school_id' => $schoolId, 'capability' => $capability, 'mode' => $mode, 'set_by' => $actor->id],
                    actor: $actor,
                );
            });
        });
    }
}
