<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authz\CapabilityService;
use App\Services\Authz\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthzTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function superAdmin(): User
    {
        $admin = $this->user('academy_admin');
        // Bootstrap grant, self-issued at seed level (real flows go through the service)
        \Illuminate\Support\Facades\DB::table('admin_capabilities')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'user_id' => $admin->id, 'capability' => 'super_admin',
            'granted_by' => $admin->id, 'granted_at' => now(),
        ]);

        return $admin;
    }

    // ── OD-1: Member denial, tested NEGATIVELY (not via the matrix probe) ──

    public function test_member_is_denied_all_four_restricted_surfaces(): void
    {
        Sanctum::actingAs($this->user('member'));

        foreach (['/api/students', '/api/consents', '/api/enrolments', '/api/payments'] as $endpoint) {
            $this->getJson($endpoint)->assertStatus(403);
        }
    }

    public function test_member_effective_permissions_are_exactly_events_rsvp_directory(): void
    {
        $member = $this->user('member');

        $this->assertSame(
            ['directory.view', 'events.rsvp', 'events.view'],
            app(PermissionResolver::class)->effectivePermissions($member),
        );
    }

    public function test_permitted_roles_pass_the_guard_to_the_stub(): void
    {
        Sanctum::actingAs($this->user('guardian'));
        $this->getJson('/api/consents')->assertStatus(501); // authorised; module not built yet
        $this->getJson('/api/payments')->assertStatus(501);
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->getJson('/api/students')->assertStatus(401);
    }

    // ── OD-17: capability grants, revocations, audit, escalation refusal ──

    public function test_grant_and_revoke_write_audit_events_naming_grantor_and_grantee(): void
    {
        $super = $this->superAdmin();
        $grantee = $this->user('academy_admin');

        Sanctum::actingAs($super);
        $this->postJson('/api/admin/capabilities/grant', [
            'user_id' => $grantee->id, 'capability' => 'finance',
        ])->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'capability.granted',
            'actor_id' => $super->id,
            'actor_role' => 'academy_admin',
            'entity_type' => 'user',
            'entity_id' => (string) $grantee->id,
        ]);
        $this->assertTrue(app(PermissionResolver::class)->allows($grantee->fresh(), 'finance.record'));

        $this->postJson('/api/admin/capabilities/revoke', [
            'user_id' => $grantee->id, 'capability' => 'finance',
        ])->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'capability.revoked',
            'actor_id' => $super->id,
            'entity_id' => (string) $grantee->id,
        ]);
        $this->assertFalse(app(PermissionResolver::class)->allows($grantee->fresh(), 'finance.record'));
    }

    public function test_finance_holder_without_super_admin_cannot_grant_and_refusal_is_audited(): void
    {
        $super = $this->superAdmin();
        $financeAdmin = $this->user('academy_admin');
        app(CapabilityService::class)->grant($super, $financeAdmin, 'finance');
        $target = $this->user('academy_admin');

        Sanctum::actingAs($financeAdmin);
        $this->postJson('/api/admin/capabilities/grant', [
            'user_id' => $target->id, 'capability' => 'operations',
        ])->assertStatus(403);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'capability.grant_refused',
            'actor_id' => $financeAdmin->id,
            'entity_id' => (string) $target->id,
        ]);
        $this->assertFalse(app(PermissionResolver::class)->allows($target->fresh(), 'operations.manage'));
    }

    public function test_non_academy_admin_cannot_hold_capabilities(): void
    {
        $super = $this->superAdmin();
        $teacher = $this->user('teacher');

        Sanctum::actingAs($super);
        $this->postJson('/api/admin/capabilities/grant', [
            'user_id' => $teacher->id, 'capability' => 'finance',
        ])->assertStatus(403);

        $this->assertDatabaseHas('audit_events', ['action' => 'capability.grant_refused', 'entity_id' => (string) $teacher->id]);
    }

    public function test_super_admin_cannot_sign_consent_and_refusal_is_audited(): void
    {
        $super = $this->superAdmin();
        Sanctum::actingAs($super);

        $this->postJson('/api/consents/sign')->assertStatus(403);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'permission.denied',
            'actor_id' => $super->id,
            'reason' => 'missing permission: consent.sign',
        ]);
        $this->assertNotContains('consent.sign', app(PermissionResolver::class)->effectivePermissions($super));
    }

    public function test_guardian_passes_the_consent_sign_guard(): void
    {
        Sanctum::actingAs($this->user('guardian'));
        $this->postJson('/api/consents/sign')->assertStatus(501); // guard passed; module is S03
    }

    // ── BI-8 / S00 §5 item 7: actor_role wired ──

    public function test_audit_events_now_carry_actor_role(): void
    {
        $teacher = $this->user('teacher');
        $event = app(\App\Services\Audit\AuditService::class)->record(
            entityType: 'sprint', entityId: 'S01', action: 'authz.smoke', actor: $teacher,
        );

        $this->assertSame('teacher', $event->fresh()->actor_role);
    }

    public function test_roles_are_not_stackable_at_the_schema(): void
    {
        // One role column, FK-constrained — a second role has nowhere to live (Spec B1)
        $user = $this->user('student');
        $this->expectException(\Illuminate\Database\QueryException::class);
        $user->forceFill(['role' => 'not_a_role'])->save();
    }
}
