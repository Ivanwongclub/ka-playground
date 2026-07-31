<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Identity\AccountActivationService;
use App\Services\Identity\LinkageService;
use App\Services\Identity\RegistrationApprovalService;
use App\Services\Reconciliation\Assertions\LinkActivationAuditedAssertion;
use App\Services\Reconciliation\Assertions\NoUnverifiedMaterialisationAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S04C STEP 3 — orphan pairs, held links, and FLAG #2. Two roads to a link (an
 * existing verified counterpart; a not-yet-registered one held then materialised
 * on verification) both terminate at approveLink(), the ONE path that reaches
 * 'active' and writes the to_state='active' audit S06 depends on.
 */
class LinkageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake(); // approval mints an activation notification
    }

    private function sys(callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->setSystem();
        try {
            return $fn();
        } finally {
            $s->reset();
        }
    }

    private function asUser(User $u, callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->set($u);
        try {
            return $fn();
        } finally {
            $s->reset();
        }
    }

    private function ops(): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(fn () => DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => 'operations',
            'granted_by' => $u->id, 'granted_at' => now(),
        ]));

        return $u;
    }

    private function verified(string $role, string $email): User
    {
        return $this->sys(fn () => tap(User::factory()->create(['role' => $role, 'email' => $email]), fn ($u) => $u->forceFill(['email_verified_at' => now()])->save()));
    }

    private function submittedRequest(string $kind, ?string $counterpartEmail = null, ?int $schoolId = null): string
    {
        $id = (string) Str::uuid7();
        $this->sys(fn () => DB::table('registration_requests')->insert([
            'id' => $id, 'kind' => $kind, 'applicant_name' => 'Appl '.Str::random(4),
            'applicant_email' => 'appl-'.Str::random(6).'@example.com', 'preferred_language' => 'en',
            'routing' => $schoolId ? 'school' : 'academy', 'school_id' => $schoolId,
            'counterpart_email' => $counterpartEmail, 'status' => 'submitted', 'reference' => Str::upper(Str::random(10)),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    /** Approve a request as the reviewer (their scope makes it visible). */
    private function approve(string $reqId, User $reviewer): User
    {
        return $this->asUser($reviewer, fn () => app(RegistrationApprovalService::class)->approve($reqId, $reviewer));
    }

    /** An unverified account carrying a known activation token; return [user, token]. */
    private function pendingAccount(string $role, string $email): array
    {
        $token = Str::random(64);
        $u = $this->sys(fn () => tap(User::factory()->create(['role' => $role, 'email' => $email]), fn ($x) => $x->forceFill([
            'email_verified_at' => null, 'activation_token_hash' => hash('sha256', $token), 'activation_expires_at' => now()->addDay(),
        ])->save()));

        return [$u, $token];
    }

    private function link(string $id): ?object
    {
        return $this->sys(fn () => DB::table('guardian_links')->where('id', $id)->first());
    }

    // ── road A: existing verified counterpart → pending link (not active) ──────

    public function test_road_a_existing_verified_counterpart_creates_a_pending_link(): void
    {
        $ops = $this->ops();
        $child = $this->verified('student', 'child-a@example.com');
        $req = $this->submittedRequest('guardian', 'child-a@example.com');

        $guardian = $this->approve($req, $ops);

        $gl = $this->sys(fn () => DB::table('guardian_links')->where('guardian_id', $guardian->id)->where('student_id', $child->id)->first());
        $this->assertNotNull($gl, 'a pending link is created for an existing verified counterpart');
        $this->assertSame('pending_approval', $gl->status);
        $this->assertSame('form_claimed', $gl->origin);
        // NOT active yet — approving the PERSON did not approve the RELATIONSHIP
        $this->assertSame(0, $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'guardian_link')->where('entity_id', $gl->id)->where('to_state', 'active')->count()));
    }

    // ── road B: not registered → held link (no guardian_link yet) ─────────────

    public function test_road_b_unregistered_counterpart_creates_a_held_link_not_a_pending_link(): void
    {
        $ops = $this->ops();
        $req = $this->submittedRequest('guardian', 'not-yet@example.com');

        $guardian = $this->approve($req, $ops);

        $held = $this->sys(fn () => DB::table('held_links')->where('claimant_id', $guardian->id)->first());
        $this->assertNotNull($held);
        $this->assertSame('held', $held->status);
        $this->assertSame('form_claimed', $held->origin);
        $this->assertSame('not-yet@example.com', $held->counterpart_email);
        // no guardian_link exists until the counterpart verifies
        $this->assertSame(0, $this->sys(fn () => DB::table('guardian_links')->where('guardian_id', $guardian->id)->count()));
    }

    // ── the TYPO SCENARIO: held stays held until the address is VERIFIED ───────

    public function test_typo_scenario_no_materialisation_before_verification_form_claimed_after(): void
    {
        $ops = $this->ops();
        // guardian claims a child address that will (unluckily) be registered by a stranger
        $guardian = $this->approve($this->submittedRequest('guardian', 'typo@example.com'), $ops);
        $held = $this->sys(fn () => DB::table('held_links')->where('claimant_id', $guardian->id)->value('id'));

        // an UNRELATED stranger holds that address as an unverified account — NOTHING materialises
        [$stranger, $token] = $this->pendingAccount('student', 'typo@example.com');
        $this->assertSame('held', $this->sys(fn () => DB::table('held_links')->where('id', $held)->value('status')), 'no materialisation before the address is verified');
        $this->assertSame(0, $this->sys(fn () => DB::table('guardian_links')->count()));

        // the stranger VERIFIES (activation) → the claim materialises, but as a
        // form-claim the reviewer can see, never a clean pending link
        app(AccountActivationService::class)->activate($token, 'StrangerPass1!');

        $held = $this->sys(fn () => DB::table('held_links')->where('id', $held)->first());
        $this->assertSame('materialised', $held->status);
        $gl = $this->sys(fn () => DB::table('guardian_links')->where('id', $held->materialised_link_id)->first());
        $this->assertSame('pending_approval', $gl->status);
        $this->assertSame('form_claimed', $gl->origin, 'a materialised held link is a form-claim, never a clean pending link');
        $this->assertSame((int) $stranger->id, (int) $gl->student_id);
        // and the invariant holds — it only materialised because the address is verified
        $this->assertTrue($this->sys(fn () => (new NoUnverifiedMaterialisationAssertion)->check()->passed));
    }

    // ── the ONE activation path writes the FLAG #2 audit — on EVERY road ───────

    public function test_flag2_audit_written_on_road_a_activation(): void
    {
        $ops = $this->ops();
        $child = $this->verified('student', 'ra@example.com');
        $guardian = $this->approve($this->submittedRequest('guardian', 'ra@example.com'), $ops);
        $glId = $this->sys(fn () => DB::table('guardian_links')->where('guardian_id', $guardian->id)->value('id'));

        // approveLink is the ONLY place it reaches active + writes to_state='active'
        $this->asUser($ops, fn () => app(LinkageService::class)->approveLink($glId, $ops));

        $this->assertSame('active', $this->link($glId)->status);
        $audit = $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'guardian_link')->where('entity_id', $glId)->where('to_state', 'active')->first());
        $this->assertNotNull($audit, 'FLAG #2: the activation audit is written');
        $this->assertSame((string) $ops->id, (string) $audit->actor_id);
        $this->assertTrue($this->sys(fn () => (new LinkActivationAuditedAssertion)->check()->passed));
    }

    public function test_flag2_audit_written_on_road_b_materialised_activation(): void
    {
        $ops = $this->ops();
        $guardian = $this->approve($this->submittedRequest('guardian', 'rb@example.com'), $ops);
        [$child, $token] = $this->pendingAccount('student', 'rb@example.com');
        app(AccountActivationService::class)->activate($token, 'ChildPass1!'); // materialises
        $glId = $this->sys(fn () => DB::table('guardian_links')->where('guardian_id', $guardian->id)->value('id'));

        $this->asUser($ops, fn () => app(LinkageService::class)->approveLink($glId, $ops));

        $this->assertSame('active', $this->link($glId)->status);
        $this->assertSame(1, $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'guardian_link')->where('entity_id', $glId)->where('to_state', 'active')->count()));
        $this->assertTrue($this->sys(fn () => (new LinkActivationAuditedAssertion)->check()->passed));
    }

    public function test_a_guardian_cannot_self_activate_their_own_pending_link(): void
    {
        $ops = $this->ops();
        $child = $this->verified('student', 'self@example.com');
        $guardian = $this->approve($this->submittedRequest('guardian', 'self@example.com'), $ops);
        $glId = $this->sys(fn () => DB::table('guardian_links')->where('guardian_id', $guardian->id)->value('id'));

        // the guardian themselves hits the endpoint → refused (reviewer roles only)
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson("/api/admin/guardian-links/{$glId}/approve")->assertStatus(403);
        $this->assertSame('pending_approval', $this->link($glId)->status);
    }

    // ── reject: the enumerated exit for a form-claim the reviewer refuses ──────

    public function test_reject_a_pending_link_is_terminal_and_audited(): void
    {
        $ops = $this->ops();
        $child = $this->verified('student', 'rej@example.com');
        $guardian = $this->approve($this->submittedRequest('guardian', 'rej@example.com'), $ops);
        $glId = $this->sys(fn () => DB::table('guardian_links')->where('guardian_id', $guardian->id)->value('id'));

        $this->asUser($ops, fn () => app(LinkageService::class)->rejectLink($glId, $ops, 'Not this family'));
        $this->assertSame('rejected', $this->link($glId)->status); // 2.30: admin refusal is 'rejected'
        $this->assertSame(1, $this->sys(fn () => DB::table('audit_events')->where('entity_id', $glId)->where('to_state', 'rejected')->count()));
    }

    // ── held-link expiry: the terminal exit for an unmaterialised claim ───────

    public function test_held_link_expiry_is_terminal(): void
    {
        $ops = $this->ops();
        $guardian = $this->approve($this->submittedRequest('guardian', 'expire@example.com'), $ops);
        // backdate the expiry
        $this->sys(fn () => DB::table('held_links')->where('claimant_id', $guardian->id)->update(['expires_at' => now()->subDay()]));

        $count = $this->sys(fn () => app(LinkageService::class)->expireHeldLinks());
        $this->assertSame(1, $count);
        $held = $this->sys(fn () => DB::table('held_links')->where('claimant_id', $guardian->id)->first());
        $this->assertSame('expired', $held->status);
        $this->assertSame(1, $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'held_link')->where('entity_id', $held->id)->where('to_state', 'expired')->count()));
    }

    // ── assertion teeth ───────────────────────────────────────────────────────

    public function test_activation_audited_reds_on_an_active_link_with_no_audit_then_greens(): void
    {
        // plant an active guardian_link with NO to_state='active' audit event
        $s = $this->verified('student', 'teeth-s@example.com');
        $g = $this->verified('guardian', 'teeth-g@example.com');
        $glId = (string) Str::uuid7();
        $this->sys(fn () => DB::table('guardian_links')->insert([
            'id' => $glId, 'student_id' => $s->id, 'guardian_id' => $g->id, 'status' => 'active',
            'origin' => 'form_claimed', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertFalse($this->sys(fn () => (new LinkActivationAuditedAssertion)->check()->passed), 'an active link with no activation audit must RED');

        // add the missing to_state='active' audit → green
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'guardian_link',
            'entity_id' => $glId, 'action' => 'guardian_link.activated', 'to_state' => 'active',
            'actor_role' => 'academy_admin', 'request_id' => (string) Str::uuid7(),
        ]));
        $this->assertTrue($this->sys(fn () => (new LinkActivationAuditedAssertion)->check()->passed));
    }

    public function test_no_unverified_materialisation_reds_then_greens(): void
    {
        // a materialised held link whose counterpart address has NO verified account
        $g = $this->verified('guardian', 'um-g@example.com');
        $hid = (string) Str::uuid7();
        $this->sys(fn () => DB::table('held_links')->insert([
            'id' => $hid, 'claimant_id' => $g->id, 'claimant_role' => 'guardian',
            'counterpart_email' => 'ghost@example.com', 'status' => 'materialised', 'origin' => 'form_claimed',
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertFalse($this->sys(fn () => (new NoUnverifiedMaterialisationAssertion)->check()->passed), 'a materialisation with no verified counterpart must RED');

        // give the address a VERIFIED account → green
        $this->verified('student', 'ghost@example.com');
        $this->assertTrue($this->sys(fn () => (new NoUnverifiedMaterialisationAssertion)->check()->passed));
    }
}
