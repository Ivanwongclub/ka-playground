<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authz\PermissionResolver;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-8 STEP 1 — member-invitation enablement (OD-22) + the directory PII boundary + the self-profile
 * read. Members are adults (invited → accept, no guardian/consent). The directory is members-only,
 * visible-only, and returns ONLY {user_id, display_name, headline} (joins only member_profiles).
 */
class MemberSurfacesUxTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $caps): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        foreach ($caps as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $c, 'granted_by' => $u->id, 'granted_at' => now()]);
        }

        return $u;
    }

    private function memberWithProfile(string $realName, string $display, string $headline, bool $visible): User
    {
        $u = User::factory()->create(['role' => 'member', 'name' => $realName]);
        $this->sys(fn () => DB::table('member_profiles')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'display_name' => $display, 'headline' => $headline, 'visible' => $visible, 'created_at' => now(), 'updated_at' => now()]));

        return $u;
    }

    private function act(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->setSystem();
        }
    }

    // ── (1) member invitable + accept — EXACTLY member caps, no teacher/school leakage ──────────────
    public function test_member_is_invitable_and_accept_creates_exactly_a_member(): void
    {
        $ops = $this->admin(['operations']);
        $this->act($ops);
        $issue = $this->postJson('/api/admin/invitations', ['email' => 'newmember@kings.test', 'role' => 'member'])
            ->assertStatus(201)->json();
        $token = $issue['token'];

        $this->app['auth']->forgetGuards(); // accept is a GUEST route
        $accept = $this->postJson('/api/onboarding/accept', ['token' => $token, 'password' => 'a-long-passphrase-123'])
            ->assertStatus(201)->json();

        $user = User::query()->findOrFail($accept['user_id']);
        $this->assertSame('member', $user->role);
        // EXACTLY the member role's permissions — no teacher/school_admin/operations leakage
        $this->assertEqualsCanonicalizing(
            ['events.view', 'events.rsvp', 'member_directory.view'],
            app(PermissionResolver::class)->effectivePermissions($user),
        );
        $this->assertSame(0, DB::table('admin_capabilities')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('teacher_links')->where('teacher_id', $user->id)->count());
    }

    // ── (2) directory privacy tooth — exact allowlist + no users PII ────────────────────────────────
    public function test_directory_carries_only_the_allowlist_never_account_pii(): void
    {
        $viewer = $this->memberWithProfile('Real Viewer', 'Viewer', 'Just here', true);
        $this->memberWithProfile('Real Peter Chan', 'Pete', 'Robotics fan', true);

        $this->act($viewer);
        $res = $this->getJson('/api/directory')->assertOk();
        $rows = $res->json('directory');

        foreach ($rows as $row) {
            $this->assertEqualsCanonicalizing(['user_id', 'display_name', 'headline'], array_keys($row));
        }
        $raw = $res->getContent();
        // the CHOSEN display name shows; the real account name / email never does
        $this->assertStringContainsString('Pete', $raw);
        $this->assertStringNotContainsString('Real Peter Chan', $raw);
        $this->assertStringNotContainsStringIgnoringCase('email', $raw);
        $this->assertStringNotContainsString('@', $raw);
    }

    // ── (3) directory five-branch ───────────────────────────────────────────────────────────────────
    public function test_directory_authority_is_five_branch(): void
    {
        $mA = $this->memberWithProfile('Real A', 'Ada', 'A', true);
        $this->memberWithProfile('Real B', 'Ben', 'B', true);
        $mC = $this->memberWithProfile('Real C', 'Cid', 'C', false); // invisible

        $names = fn () => array_column($this->getJson('/api/directory')->assertOk()->json('directory'), 'display_name');

        // member → the VISIBLE profiles; the invisible one (Cid) never appears via /directory
        $this->act($mA);
        $this->assertEqualsCanonicalizing(['Ada', 'Ben'], $names());

        // the opt-out is ABSOLUTE in /directory (the controller filters visible=true for everyone) — Cid
        // is absent even from ops and even from Cid's OWN /directory; Cid sees their own via /my/profile.
        $this->act($mC);
        $this->assertNotContains('Cid', $names());
        $this->assertSame('Cid', $this->getJson('/api/my/profile')->assertOk()->json('display_name')); // own via /my/profile

        // ops → passes the member-gate but /directory still filters visible → visible profiles only
        $this->act($this->admin(['audit_read']));
        $this->assertEqualsCanonicalizing(['Ada', 'Ben'], $names());

        // non-members → nothing (the member marker gates the whole branch)
        foreach (['student', 'guardian', 'teacher', 'school_admin'] as $role) {
            $this->act(User::factory()->create(['role' => $role]));
            $this->assertSame([], $this->getJson('/api/directory')->assertOk()->json('directory'), "{$role} must not see the directory");
        }

        // unauthenticated → 401
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/directory')->assertUnauthorized();
    }

    // ── (4) RSVP clean write ────────────────────────────────────────────────────────────────────────
    public function test_rsvp_is_published_only_and_per_member(): void
    {
        $ops = $this->admin(['operations']);
        [$published, $draft] = $this->sys(function () use ($ops) {
            $p = (string) Str::uuid7();
            $d = (string) Str::uuid7();
            DB::table('events')->insert([
                ['id' => $p, 'title_en' => 'Gala', 'title_tc' => 'Gala', 'title_sc' => 'Gala', 'starts_at' => now()->addDays(5), 'location' => 'HK', 'status' => 'published', 'created_by' => $ops->id, 'created_at' => now(), 'updated_at' => now()],
                ['id' => $d, 'title_en' => 'Draft', 'title_tc' => 'Draft', 'title_sc' => 'Draft', 'starts_at' => now()->addDays(9), 'location' => 'HK', 'status' => 'draft', 'created_by' => $ops->id, 'created_at' => now(), 'updated_at' => now()],
            ]);

            return [$p, $d];
        });

        $mA = User::factory()->create(['role' => 'member']);
        $mB = User::factory()->create(['role' => 'member']);

        $this->act($mA);
        $this->postJson("/api/events/{$published}/rsvp", ['status' => 'going'])->assertOk();
        $this->postJson("/api/events/{$draft}/rsvp", ['status' => 'going'])->assertStatus(409); // only-published

        $mine = $this->getJson('/api/my/rsvps')->assertOk()->json('rsvps');
        $this->assertCount(1, $mine);
        $this->assertSame($published, $mine[0]['event_id']);

        // a DIFFERENT member sees none of mA's rsvps (per-member)
        $this->act($mB);
        $this->assertSame([], $this->getJson('/api/my/rsvps')->assertOk()->json('rsvps'));
    }

    // ── (5) profile self-scoped (read + write own only) ─────────────────────────────────────────────
    public function test_profile_is_self_scoped_read_and_write(): void
    {
        $m = User::factory()->create(['role' => 'member']);
        $this->act($m);

        // GET before any save → creatable/null shape, not an error
        $this->getJson('/api/my/profile')->assertOk()->assertJsonPath('display_name', null)->assertJsonPath('visible', true);

        $this->putJson('/api/my/profile', ['display_name' => 'Solo', 'headline' => 'Hi', 'visible' => false])->assertOk();

        $mine = $this->getJson('/api/my/profile')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['display_name', 'headline', 'visible'], array_keys($mine));
        $this->assertSame(['Solo', 'Hi', false], [$mine['display_name'], $mine['headline'], $mine['visible']]);
        // the row is the member's OWN (no param exists to touch another member's)
        $this->assertSame($m->id, (int) $this->sys(fn () => DB::table('member_profiles')->where('display_name', 'Solo')->value('user_id')));
    }
}
