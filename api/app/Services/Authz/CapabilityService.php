<?php

namespace App\Services\Authz;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Capability grants/revocations (OD-17). Every outcome is audited — including
 * refusals: an escalation attempt is exactly the event an auditor wants to see.
 */
class CapabilityService
{
    public const CAPABILITIES = ['super_admin', 'configuration', 'finance', 'operations', 'audit_read'];

    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly AuditService $audit,
    ) {}

    public function grant(User $actor, User $grantee, string $capability): void
    {
        $this->assertKnown($capability);
        $this->authorize($actor, $grantee, $capability, 'grant');

        $alreadyActive = DB::table('admin_capabilities')
            ->where('user_id', $grantee->id)
            ->where('capability', $capability)
            ->whereNull('revoked_at')
            ->exists();
        if ($alreadyActive) {
            return; // idempotent — no duplicate active grant, no duplicate audit noise
        }

        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $grantee->id,
            'capability' => $capability,
            'granted_by' => $actor->id,
            'granted_at' => now(),
        ]);

        $this->audit->record(
            entityType: 'user',
            entityId: (string) $grantee->id,
            action: 'capability.granted',
            toState: $capability,
            payloadAfter: ['capability' => $capability, 'grantor_id' => $actor->id, 'grantee_id' => $grantee->id],
            actor: $actor,
        );
    }

    public function revoke(User $actor, User $grantee, string $capability): void
    {
        $this->assertKnown($capability);
        $this->authorize($actor, $grantee, $capability, 'revoke');

        $updated = DB::table('admin_capabilities')
            ->where('user_id', $grantee->id)
            ->where('capability', $capability)
            ->whereNull('revoked_at')
            ->update(['revoked_by' => $actor->id, 'revoked_at' => now()]);

        if ($updated === 0) {
            return; // nothing active to revoke
        }

        $this->audit->record(
            entityType: 'user',
            entityId: (string) $grantee->id,
            action: 'capability.revoked',
            fromState: $capability,
            payloadAfter: ['capability' => $capability, 'grantor_id' => $actor->id, 'grantee_id' => $grantee->id],
            actor: $actor,
        );
    }

    private function assertKnown(string $capability): void
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw new InvalidArgumentException("Unknown capability '{$capability}'");
        }
    }

    private function authorize(User $actor, User $grantee, string $capability, string $verb): void
    {
        $refusalReason = null;
        if (! $this->resolver->allows($actor, 'capabilities.grant')) {
            $refusalReason = "actor lacks capabilities.grant (super_admin required) — attempted {$verb} of '{$capability}'";
        } elseif ($grantee->role !== 'academy_admin') {
            $refusalReason = "grantee role '{$grantee->role}' cannot hold capabilities (OD-17: Academy Administrator only)";
        }

        if ($refusalReason !== null) {
            // The refusal itself is audited (S01 card, Leo amendment 3)
            $this->audit->record(
                entityType: 'user',
                entityId: (string) $grantee->id,
                action: "capability.{$verb}_refused",
                reason: $refusalReason,
                payloadAfter: ['capability' => $capability, 'attempted_by' => $actor->id, 'grantee_id' => $grantee->id],
                actor: $actor,
            );
            throw new AuthorizationException($refusalReason);
        }
    }
}
