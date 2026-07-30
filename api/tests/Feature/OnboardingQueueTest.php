<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Identity\LinkageService;
use App\Services\Identity\OnboardingQueueService;
use App\Services\Reconciliation\Assertions\AccountProvenanceAssertion;
use App\Services\Reconciliation\Assertions\QueueEscalationLivenessAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S04C STEP 4 — the ONE queue, its escalation liveness, and account provenance.
 */
class OnboardingQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        \Illuminate\Support\Facades\Notification::fake();
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

    private function ops(): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        $this->sys(fn () => DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => 'operations', 'granted_by' => $u->id, 'granted_at' => now()]));

        return $u;
    }

    private function submittedRequest(string $kind = 'guardian', ?string $counterpart = null, ?string $createdAt = null): string
    {
        $id = (string) Str::uuid7();
        $this->sys(fn () => DB::table('registration_requests')->insert([
            'id' => $id, 'kind' => $kind, 'applicant_name' => 'A '.Str::random(4), 'applicant_email' => 'a-'.Str::random(6).'@ex.com',
            'preferred_language' => 'en', 'routing' => 'academy', 'counterpart_email' => $counterpart, 'status' => 'submitted',
            'reference' => Str::upper(Str::random(10)), 'created_at' => $createdAt ?? now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function sanctumAs(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    // ── the queue ──────────────────────────────────────────────────────────────

    public function test_queue_lists_pending_accounts_links_and_held_with_age(): void
    {
        $ops = $this->ops();
        $this->submittedRequest('guardian', null, now()->subDays(3)->toDateTimeString());
        // a pending link + a held link
        $s = $this->sys(fn () => User::factory()->create(['role' => 'student']));
        $g = $this->sys(fn () => User::factory()->create(['role' => 'guardian']));
        $this->sys(fn () => DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $s->id, 'guardian_id' => $g->id, 'status' => 'pending_approval', 'origin' => 'form_claimed', 'created_at' => now()->subDays(2), 'updated_at' => now()]));
        $this->sys(fn () => DB::table('held_links')->insert(['id' => (string) Str::uuid7(), 'claimant_id' => $g->id, 'claimant_role' => 'guardian', 'counterpart_email' => 'x@ex.com', 'status' => 'held', 'origin' => 'form_claimed', 'expires_at' => now()->addDays(90), 'created_at' => now()->subDays(1), 'updated_at' => now()]));

        $this->sanctumAs($ops);
        $r = $this->getJson('/api/admin/onboarding-queue')->assertOk()->json();
        $this->assertSame(7, $r['threshold_days']);
        $this->assertCount(1, $r['accounts']);
        $this->assertSame(3, $r['accounts'][0]['age_days']);
        $this->assertCount(1, $r['links']);
        $this->assertCount(1, $r['held']);
    }

    public function test_a_family_role_cannot_read_the_queue(): void
    {
        $this->sanctumAs(User::factory()->create(['role' => 'guardian']));
        $this->getJson('/api/admin/onboarding-queue')->assertStatus(403);
    }

    // ── escalation + liveness ──────────────────────────────────────────────────

    public function test_escalation_raises_an_exception_for_an_over_threshold_item_idempotently(): void
    {
        $this->ops();
        $reqId = $this->submittedRequest('guardian', null, now()->subDays(10)->toDateTimeString());

        $raised = $this->sys(fn () => app(OnboardingQueueService::class)->escalate());
        $this->assertSame(1, $raised);
        $this->assertSame(1, $this->sys(fn () => DB::table('onboarding_exceptions')->where('subject_id', $reqId)->where('status', 'open')->count()));
        // idempotent — a second sweep raises nothing new
        $this->assertSame(0, $this->sys(fn () => app(OnboardingQueueService::class)->escalate()));
    }

    public function test_escalation_liveness_reds_on_a_stale_item_then_greens(): void
    {
        $reqId = $this->submittedRequest('guardian', null, now()->subDays(10)->toDateTimeString());
        // over threshold, no exception yet → RED
        $this->assertFalse($this->sys(fn () => (new QueueEscalationLivenessAssertion)->check()->passed));
        // the sweep raises it → GREEN
        $this->sys(fn () => app(OnboardingQueueService::class)->escalate());
        $this->assertTrue($this->sys(fn () => (new QueueEscalationLivenessAssertion)->check()->passed));
    }

    // ── account provenance ─────────────────────────────────────────────────────

    public function test_account_provenance_reds_on_an_origin_less_account_then_greens(): void
    {
        // a bare user with NO provenance audit → RED
        $u = $this->sys(fn () => User::factory()->create(['role' => 'guardian']));
        $this->assertFalse($this->sys(fn () => (new AccountProvenanceAssertion)->check()->passed), 'an account with no origin audit must RED');
        // give it a user.created audit → GREEN
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'user', 'entity_id' => (string) $u->id,
            'action' => 'user.created', 'to_state' => 'registered', 'actor_role' => 'system', 'request_id' => (string) Str::uuid7(),
        ]));
        $this->assertTrue($this->sys(fn () => (new AccountProvenanceAssertion)->check()->passed));
    }

    // ── two decisions, two audit rows (combined account+link item) ─────────────

    public function test_combined_item_is_two_decisions_two_audit_rows_with_own_timestamps(): void
    {
        $ops = $this->ops();
        // existing verified counterpart so approval creates BOTH an account and a pending link
        $child = $this->sys(fn () => tap(User::factory()->create(['role' => 'student', 'email' => 'cc@ex.com']), fn ($u) => $u->forceFill(['email_verified_at' => now()])->save()));
        $reqId = $this->submittedRequest('guardian', 'cc@ex.com');

        // DECISION 1 — approve the account (registration.approved)
        $this->sanctumAs($ops);
        $accountId = $this->postJson("/api/admin/registration-requests/{$reqId}/approve")->assertStatus(201)->json('account_id');
        $glId = $this->sys(fn () => DB::table('guardian_links')->where('guardian_id', $accountId)->value('id'));

        // DECISION 2 — approve the relationship (guardian_link.activated) — separate endpoint
        $this->postJson("/api/admin/guardian-links/{$glId}/approve")->assertOk();

        // TWO distinct audit rows, each with its own timestamp; neither decision wrote the other
        [$accountDecision, $linkDecision] = $this->sys(fn () => [
            DB::table('audit_events')->where('action', 'registration.approved')->where('entity_id', $reqId)->first(),
            DB::table('audit_events')->where('action', 'guardian_link.activated')->where('entity_id', $glId)->where('to_state', 'active')->first(),
        ]);
        $this->assertNotNull($accountDecision);
        $this->assertNotNull($linkDecision);
        $this->assertSame((string) $ops->id, (string) $accountDecision->actor_id);
        $this->assertSame((string) $ops->id, (string) $linkDecision->actor_id);
        // two separate decisions — different entity types, each its own record
        $this->assertSame('registration_request', $accountDecision->entity_type);
        $this->assertSame('guardian_link', $linkDecision->entity_type);
    }
}
