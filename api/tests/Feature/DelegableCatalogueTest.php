<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A-1 — the delegable-capability catalogue is the delegation SAFETY SPINE. This test asserts the
 * never-delegable invariants DIRECTLY, as belt-and-suspenders alongside the
 * authz.delegable_catalogue_integrity reconcile probe: a dangerous capability can never silently become
 * delegable, and every permission stays classified exactly once. Config-only, no DB.
 */
class DelegableCatalogueTest extends TestCase
{
    /** @var list<string> */
    private array $delegable;

    /** @var list<string> */
    private array $never;

    /** @var array<string, string> */
    private array $reserved;

    /** @var list<string> */
    private array $permissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->delegable = config('delegable-capabilities.delegable');
        $this->never = config('delegable-capabilities.never');
        $this->reserved = config('delegable-capabilities.never_reserved');
        $this->permissions = config('permission-matrix.permissions');
    }

    public function test_hard_never_spine_is_never_delegable(): void
    {
        foreach (['consent.sign', 'finance.confirm', 'capabilities.grant', 'configuration.manage'] as $key) {
            $this->assertContains($key, $this->never, "{$key} must be in the NEVER set (hard spine)");
            $this->assertNotContains($key, $this->delegable, "{$key} must NOT be delegable");
        }
    }

    public function test_full_never_set_including_confirmed_judgment_calls(): void
    {
        // The four hard minimums + the four confirmed judgment NEVERs (A-1 ruling).
        foreach ([
            'consent.sign', 'finance.record', 'finance.confirm', 'capabilities.grant',
            'configuration.manage', 'operations.manage', 'audit.read', 'member_directory.view',
        ] as $key) {
            $this->assertContains($key, $this->never, "{$key} must be never-delegable");
            $this->assertNotContains($key, $this->delegable, "{$key} must NOT appear in delegable");
        }
    }

    public function test_delegable_and_never_are_disjoint(): void
    {
        $this->assertSame(
            [],
            array_values(array_intersect($this->delegable, $this->never)),
            'no permission may be classified BOTH delegable and never',
        );
    }

    public function test_every_permission_is_classified_exactly_once(): void
    {
        $classified = array_unique(array_merge($this->delegable, $this->never));
        sort($classified);
        $perms = $this->permissions;
        sort($perms);

        $this->assertSame(
            $perms,
            $classified,
            'delegable ∪ never must equal the full permission set — nothing unclassified, no orphan/typo, no duplicate',
        );
    }

    public function test_formed_team_marker_is_present(): void
    {
        $this->assertSame(
            'platform_only',
            $this->reserved['teams.formed_membership_mutation'] ?? null,
            'the reserved formed-team membership-mutation marker must record platform_only',
        );
    }
}
