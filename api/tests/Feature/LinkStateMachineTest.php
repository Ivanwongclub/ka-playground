<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Identity\AccountMintingService;
use App\Services\Identity\InvitationService;
use App\Services\Reconciliation\Assertions\NoActiveWithoutApprovalAssertion;
use App\Services\Teams\TrackerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S04D STEP 1 — enum + origin + the AccountMintingService extraction + the
 * doAccept activation audit + the backfill + the all-three assertion + the
 * gate-authority guardrail. (The write-policy hardening is STEP 3, deferred.)
 */
class LinkStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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

    // ── AccountMintingService preserves credential-only-at-activation (D-iii) ──

    public function test_mint_pending_activation_never_leaves_a_usable_password(): void
    {
        $minted = $this->sys(fn () => app(AccountMintingService::class)->mintPendingActivation('A Parent', 'mint@example.com', 'guardian'));
        $user = $minted['user'];

        $this->assertNull($user->email_verified_at, 'born UNVERIFIED');
        $this->assertNotEmpty($minted['token'], 'the activation token is returned once');
        $this->assertNotNull($this->sys(fn () => DB::table('users')->where('id', $user->id)->value('activation_token_hash')), 'the token hash is stored');
        // the placeholder password is unusable — no login before activation
        $this->postJson('/api/auth/login', ['email' => 'mint@example.com', 'password' => 'anything-guessed'])->assertStatus(422);
        // and it is genuinely random (a known weak guess does not match)
        $hash = $this->sys(fn () => DB::table('users')->where('id', $user->id)->value('password'));
        $this->assertFalse(Hash::check('password', $hash));
    }

    // ── doAccept now audits the teacher_link activation (the ex-un-audited path) ──

    public function test_doaccept_audits_the_teacher_link_activation(): void
    {
        Notification::fake();
        $ops = $this->ops();
        $school = $this->sys(fn () => School::query()->create(['name_en' => 'S', 'name_tc' => '甲', 'name_sc' => '甲']));
        $issued = $this->sys(fn () => app(InvitationService::class)->issue($ops, 'teach@example.com', 'teacher', $school->id));
        $user = app(InvitationService::class)->accept($issued['plain_token'], 'Chosen-Pass-12345');

        $tl = $this->sys(fn () => DB::table('teacher_links')->where('teacher_id', $user->id)->where('status', 'active')->first());
        $this->assertNotNull($tl);
        $this->assertSame('invitation', $tl->origin);
        $this->assertSame(1, $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'teacher_link')->where('entity_id', $tl->id)->where('to_state', 'active')->count()), 'the teacher_link activation is now audited');
    }

    // ── backfill: audit-less active links get an activation audit keyed on real creation ──

    public function test_backfill_audits_active_links_keyed_on_real_creation(): void
    {
        $s = $this->sys(fn () => User::factory()->create(['role' => 'student']));
        $g = $this->sys(fn () => User::factory()->create(['role' => 'guardian']));
        $school = $this->sys(fn () => School::query()->create(['name_en' => 'S', 'name_tc' => '甲', 'name_sc' => '甲']));
        $t = $this->sys(fn () => User::factory()->create(['role' => 'teacher']));
        $created = now()->subDays(5)->startOfSecond();

        $tlId = (string) Str::uuid7();
        $this->sys(function () use ($s, $g, $school, $t, $created, $tlId) {
            // active links with NO to_state='active' audit (simulating pre-S04D history)
            DB::table('teacher_links')->insert(['id' => $tlId, 'teacher_id' => $t->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => $created, 'updated_at' => $created]);
        });

        // run the backfill (the migration's query, on this planted data)
        $this->sys(fn () => DB::unprepared(
            "INSERT INTO audit_events (event_id, occurred_at, entity_type, entity_id, action, to_state, actor_role, request_id)
             SELECT gen_random_uuid(), l.created_at, 'teacher_link', l.id::text, 'link.legacy_approved', 'active', 'system', gen_random_uuid()
             FROM teacher_links l WHERE l.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM audit_events ae WHERE ae.entity_type = 'teacher_link' AND ae.entity_id = l.id::text AND ae.to_state = 'active')"
        ));

        $audit = $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'teacher_link')->where('entity_id', $tlId)->where('to_state', 'active')->first());
        $this->assertNotNull($audit);
        $this->assertSame('link.legacy_approved', $audit->action);
        $this->assertSame($created->toDateTimeString(), \Illuminate\Support\Carbon::parse($audit->occurred_at)->toDateTimeString(), 'keyed on the real created_at, not now()');
    }

    // ── links.no_active_without_approval teeth (all three tables) ──────────────

    public function test_no_active_without_approval_reds_then_greens(): void
    {
        // an active school_link with NO activation audit → RED
        $student = $this->sys(fn () => User::factory()->create(['role' => 'student']));
        $school = $this->sys(fn () => School::query()->create(['name_en' => 'S', 'name_tc' => '甲', 'name_sc' => '甲']));
        $slId = (string) Str::uuid7();
        $this->sys(fn () => DB::table('school_links')->insert(['id' => $slId, 'student_id' => $student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertFalse($this->sys(fn () => (new NoActiveWithoutApprovalAssertion)->check()->passed), 'an active link with no activation audit must RED');

        $this->sys(fn () => DB::table('audit_events')->insert(['event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'school_link', 'entity_id' => $slId, 'action' => 'school_link.created', 'to_state' => 'active', 'actor_role' => 'system', 'request_id' => (string) Str::uuid7()]));
        $this->assertTrue($this->sys(fn () => (new NoActiveWithoutApprovalAssertion)->check()->passed));
    }

    // ── GATE-AUTHORITY GUARDRAIL: teacher↔school grants NO gate authority ──────

    public function test_a_school_linked_teacher_gains_no_gate_authority(): void
    {
        $teacher = $this->sys(fn () => User::factory()->create(['role' => 'teacher']));
        $school = $this->sys(fn () => School::query()->create(['name_en' => 'S', 'name_tc' => '甲', 'name_sc' => '甲']));
        // a teacher↔SCHOOL affiliation (S04D teacher_links) — NOT a teacher↔TEAM link
        $this->sys(fn () => DB::table('teacher_links')->insert(['id' => (string) Str::uuid7(), 'teacher_id' => $teacher->id, 'school_id' => $school->id, 'status' => 'active', 'origin' => 'invitation', 'created_at' => now(), 'updated_at' => now()]));

        $team = (object) ['id' => (string) Str::uuid7(), 'category_id' => null];
        $svc = app(TrackerService::class);
        $m = new \ReflectionMethod($svc, 'gateApproverKind');
        $m->setAccessible(true);

        // gateApproverKind reads team_teacher_links ONLY (OD-61) — a school-linked
        // teacher with no team link falls through to the 403.
        $this->sys(function () use ($m, $svc, $teacher, $team) {
            try {
                $m->invoke($svc, $teacher, $team);
                $this->fail('a school-linked teacher must NOT gain approve-any-team authority');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        });

        // and WITH a team_teacher_link the same teacher IS the team's approver
        $this->sys(fn () => DB::table('team_teacher_links')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team->id, 'category_id' => (string) Str::uuid7(), 'teacher_id' => $teacher->id, 'created_by' => $teacher->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSame('teacher', $this->sys(fn () => $m->invoke($svc, $teacher, $team)));
    }
}
