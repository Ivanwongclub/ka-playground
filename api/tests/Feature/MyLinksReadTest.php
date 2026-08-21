<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-READ-3 item 1 — RLS PROOF (RIDER-1) for GET /api/my/children and GET /api/my/guardians.
 *
 * Both are SELF reads under `guardian_links_read`, which carries a status-blind arm per side:
 *   guardian_id::text = app.actor_id   (the guardian's)   |   student_id::text = app.actor_id  (the student's)
 * so this proves, empirically per seeded role: each family reaches its OWN links and no other family's, in
 * BOTH directions; every non-family role is refused at the route; and the payload carries names for ACTIVE
 * links only (F-1/F-2) and NO contact fields at all — asserted against the RAW BODY, so a nested leak cannot
 * hide behind a key-set check.
 */
class MyLinksReadTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;

    private User $studentB;

    private User $guardianA;   // active link to studentA, pending_approval link to pendingChild

    private User $guardianB;   // active link to studentB — the cross-family probe

    private User $pendingChild;

    private User $lonelyGuardian; // no links at all

    protected function setUp(): void
    {
        parent::setUp(); // harness system context — setup inserts bypass RLS legitimately
        $this->studentA = User::factory()->create(['role' => 'student', 'name' => 'Child Alpha']);
        $this->studentB = User::factory()->create(['role' => 'student', 'name' => 'Child Bravo']);
        $this->pendingChild = User::factory()->create(['role' => 'student', 'name' => 'Child Pending']);
        $this->guardianA = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Alpha']);
        $this->guardianB = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Bravo']);
        $this->lonelyGuardian = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Lonely']);

        $this->link($this->studentA->id, $this->guardianA->id, 'active');
        $this->link($this->pendingChild->id, $this->guardianA->id, 'pending_approval');
        $this->link($this->studentB->id, $this->guardianB->id, 'active');
    }

    private function link(int $studentId, int $guardianId, string $status): void
    {
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $studentId, 'guardian_id' => $guardianId,
            'status' => $status, 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Every field the endpoints must NEVER carry, checked against the serialised body. */
    private function assertNoContactFields(string $body): void
    {
        foreach (['email', 'phone', 'mobile', 'address', 'dob', 'date_of_birth', 'password'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "the links payload leaked a `{$forbidden}` field");
        }
    }

    // ── /my/children — the guardian's side ───────────────────────────────────────────────────────────

    public function test_guardian_reads_own_links_only_and_never_another_family(): void
    {
        Sanctum::actingAs($this->guardianA);
        $r = $this->getJson('/api/my/children')->assertOk();
        $ids = collect($r->json('data'))->pluck('student_id')->all();

        sort($ids);
        $expected = [$this->studentA->id, $this->pendingChild->id];
        sort($expected);
        $this->assertSame($expected, $ids);                              // exactly A's two links
        $this->assertNotContains($this->studentB->id, $ids);             // CROSS-FAMILY: B's child is absent
    }

    public function test_cross_family_holds_in_the_other_direction(): void
    {
        Sanctum::actingAs($this->guardianB);
        $ids = collect($this->getJson('/api/my/children')->assertOk()->json('data'))->pluck('student_id')->all();
        $this->assertSame([$this->studentB->id], $ids);
        $this->assertNotContains($this->studentA->id, $ids);
    }

    public function test_guardian_with_no_links_gets_an_empty_list_not_a_404(): void
    {
        // The zero-enrolment invisibility hole this read closes: a guardian who has just registered must get
        // a truthful empty list, never an error that reads as "something is broken".
        Sanctum::actingAs($this->lonelyGuardian);
        $this->getJson('/api/my/children')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_name_is_served_for_active_links_and_withheld_for_pending_ones(): void
    {
        Sanctum::actingAs($this->guardianA);
        $rows = collect($this->getJson('/api/my/children')->assertOk()->json('data'))->keyBy('student_id');

        // ACTIVE — the name rides the caller's OWN users_read arm (app.student_ids), no elevation.
        $this->assertSame('Child Alpha', $rows[$this->studentA->id]['name']);
        $this->assertSame('active', $rows[$this->studentA->id]['status']);

        // PENDING (F-1) — the ceremony has not been approved, so the child is nameless. The row still exists,
        // carrying its state: the guardian learns the link's progress without being handed the prize early.
        $this->assertNull($rows[$this->pendingChild->id]['name']);
        $this->assertSame('pending_approval', $rows[$this->pendingChild->id]['status']);
    }

    public function test_children_payload_carries_no_contact_fields(): void
    {
        Sanctum::actingAs($this->guardianA);
        $body = $this->getJson('/api/my/children')->assertOk()->getContent();
        $this->assertNoContactFields($body);
        $this->assertSame(['student_id', 'name', 'status'], array_keys($this->getJson('/api/my/children')->json('data')[0]));
    }

    // ── /my/guardians — the student's side, the elevated one ─────────────────────────────────────────

    public function test_student_reads_own_guardians_only(): void
    {
        Sanctum::actingAs($this->studentA);
        $rows = $this->getJson('/api/my/guardians')->assertOk()->json('data');
        $this->assertSame([$this->guardianA->id], collect($rows)->pluck('guardian_id')->all());
        $this->assertSame('Guardian Alpha', $rows[0]['name']);   // ACTIVE → the AD-2 elevation resolves it
    }

    public function test_student_side_cross_family_holds(): void
    {
        Sanctum::actingAs($this->studentB);
        $ids = collect($this->getJson('/api/my/guardians')->assertOk()->json('data'))->pluck('guardian_id')->all();
        $this->assertSame([$this->guardianB->id], $ids);
        $this->assertNotContains($this->guardianA->id, $ids);
    }

    public function test_pending_guardian_is_nameless_to_the_student_too(): void
    {
        // F-1 mirrored: the elevation resolves names for ACTIVE links only, so an unapproved would-be guardian
        // never becomes a name on the student's screen.
        Sanctum::actingAs($this->pendingChild);
        $rows = $this->getJson('/api/my/guardians')->assertOk()->json('data');
        $this->assertSame([$this->guardianA->id], collect($rows)->pluck('guardian_id')->all());
        $this->assertNull($rows[0]['name']);
        $this->assertSame('pending_approval', $rows[0]['status']);
    }

    public function test_student_with_no_links_gets_an_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->getJson('/api/my/guardians')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_guardians_payload_carries_no_contact_fields(): void
    {
        Sanctum::actingAs($this->studentA);
        $this->assertNoContactFields($this->getJson('/api/my/guardians')->assertOk()->getContent());
        $this->assertSame(['guardian_id', 'name', 'status'], array_keys($this->getJson('/api/my/guardians')->json('data')[0]));
    }

    // ── role refusals: each endpoint answers ONE persona ──────────────────────────────────────────────

    public function test_every_other_role_is_refused_on_both_endpoints(): void
    {
        $cases = [
            'student on /my/children' => [$this->studentA, '/api/my/children'],
            'guardian on /my/guardians' => [$this->guardianA, '/api/my/guardians'],
        ];
        foreach ($this->outsiders() as $label => $user) {
            $cases["{$label} on /my/children"] = [$user, '/api/my/children'];
            $cases["{$label} on /my/guardians"] = [$user, '/api/my/guardians'];
        }

        foreach ($cases as $label => [$user, $uri]) {
            Sanctum::actingAs($user);
            $r = $this->getJson($uri);
            $this->assertSame(403, $r->status(), "{$label}: expected 403, got {$r->status()}");
            $this->assertNull($r->json('data'), "{$label}: a refusal must not leak a body");
        }
    }

    /** @return array<string, User> */
    private function outsiders(): array
    {
        return [
            'teacher' => User::factory()->create(['role' => 'teacher']),
            'school_admin' => User::factory()->create(['role' => 'school_admin']),
            'member' => User::factory()->create(['role' => 'member']),
            // An academy admin is refused too: these are SELF reads, not admin reads. Ops/audit reach links
            // through their own admin surfaces, which carry their own audit trail.
            'academy_admin(operations)' => $this->academyAdminWith('operations'),
            'academy_admin(super_admin)' => $this->academyAdminWith('super_admin'),
            'academy_admin(audit_read)' => $this->academyAdminWith('audit_read'),
        ];
    }

    private function academyAdminWith(string $capability): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $capability,
            'granted_by' => $u->id, 'granted_at' => now(),
        ]);

        return $u;
    }

    public function test_unauthenticated_is_refused_on_both(): void
    {
        $this->getJson('/api/my/children')->assertStatus(401);
        $this->getJson('/api/my/guardians')->assertStatus(401);
    }
}
