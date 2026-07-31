<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\GuardianAdditionVisibilityAssertion;
use App\Services\Reconciliation\Assertions\VouchScopeAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S04D STEP 3 — school vouch (OD-30) + OD-24 never-silent visibility + the
 * deferred write-policy hardening + the second-guardian refusals.
 */
class VouchVisibilityTest extends TestCase
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

    private function under(User $u, callable $fn): mixed
    {
        $s = app(ScopeContext::class);
        $s->set($u);
        try {
            return $fn();
        } finally {
            $s->reset();
        }
    }

    private function school(): School
    {
        return $this->sys(fn () => School::query()->create(['name_en' => 'S'.Str::random(3), 'name_tc' => '甲', 'name_sc' => '甲']));
    }

    private function schoolAdmin(int $schoolId): User
    {
        $u = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $u->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        return $u;
    }

    /** A student on $school's roll, already linked to one active guardian G1. @return array{0:User,1:User} */
    private function studentWithGuardian(School $school): array
    {
        $student = User::factory()->create(['role' => 'student']);
        $g1 = User::factory()->create(['role' => 'guardian']);
        $this->sys(function () use ($student, $g1, $school) {
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $glId = (string) Str::uuid7();
            DB::table('guardian_links')->insert(['id' => $glId, 'student_id' => $student->id, 'guardian_id' => $g1->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now()->subDay(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['event_id' => (string) Str::uuid7(), 'occurred_at' => now()->subDay(), 'entity_type' => 'guardian_link', 'entity_id' => $glId, 'action' => 'guardian_link.created', 'to_state' => 'active', 'actor_role' => 'system', 'request_id' => (string) Str::uuid7()]);
        });

        return [$student, $g1];
    }

    // ── vouch: elevated active write + OD-24 visibility to the existing guardian ──

    public function test_vouch_activates_and_writes_visibility_to_existing_guardian(): void
    {
        $school = $this->school();
        [$student, $g1] = $this->studentWithGuardian($school);
        $g2 = User::factory()->create(['role' => 'guardian', 'email' => 'g2@example.com']);
        $admin = $this->schoolAdmin($school->id);

        Sanctum::actingAs($admin);
        $this->postJson('/api/school/guardian-links', ['student_id' => $student->id, 'guardian_email' => 'g2@example.com'])->assertStatus(201);

        // G2 active (vouched); G1 gets a visibility record for the addition (never silent)
        $gl = $this->sys(fn () => DB::table('guardian_links')->where('student_id', $student->id)->where('guardian_id', $g2->id)->first());
        $this->assertSame('active', $gl->status);
        $this->assertSame('school_mediated', $gl->origin);
        $vis = $this->sys(fn () => DB::table('link_visibility_events')->where('new_link_id', $gl->id)->where('addressed_guardian_id', $g1->id)->first());
        $this->assertNotNull($vis, 'the existing guardian G1 gets a visibility record (OD-24)');
        $this->assertSame('school_mediated', $vis->origin);
        // the first guardian SEES it in their own scope; another guardian does not
        $this->assertSame(1, $this->under($g1, fn () => DB::table('link_visibility_events')->count()));
        $this->assertSame(0, $this->under($g2, fn () => DB::table('link_visibility_events')->count()));
        // both governance assertions hold
        $this->assertTrue($this->sys(fn () => (new GuardianAdditionVisibilityAssertion)->check()->passed));
        $this->assertTrue($this->sys(fn () => (new VouchScopeAssertion)->check()->passed));
    }

    // ── OD-24 refusals: a non-vouch second-guardian self-add ──────────────────

    public function test_pairing_redeem_refuses_an_uninitiated_second_guardian(): void
    {
        $school = $this->school();
        [$student, ] = $this->studentWithGuardian($school);
        Sanctum::actingAs($student);
        $code = $this->postJson('/api/my/pairing-codes')->json('code');

        // a DIFFERENT guardian (not G1) redeems → OD-24 refusal
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->postJson('/api/pairing-codes/redeem', ['code' => $code])->assertStatus(422);
        $this->assertSame(1, $this->sys(fn () => DB::table('guardian_links')->where('student_id', $student->id)->count()), 'no second link created');
    }

    public function test_request_by_email_second_guardian_is_silent_202_no_link(): void
    {
        $school = $this->school();
        [$student, ] = $this->studentWithGuardian($school);
        $student->forceFill(['email' => 'has-guardian@example.com'])->save();

        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        // constant-shape 202 (never leak that the student already has a guardian), no link
        $this->postJson('/api/my/link-requests', ['student_email' => 'has-guardian@example.com'])->assertStatus(202);
        $this->assertSame(1, $this->sys(fn () => DB::table('guardian_links')->where('student_id', $student->id)->count()), 'no second link created');
    }

    // ── the deferred write-policy hardening: only system writes status='active' ──

    public function test_stray_direct_active_write_is_refused_but_system_activates(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $guardian = User::factory()->create(['role' => 'guardian']);

        // a NON-system actor (the guardian) cannot write an active guardian_link at the DB
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->under($guardian, fn () => DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_non_system_may_still_write_a_pending_row_and_system_writes_active(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $guardian = User::factory()->create(['role' => 'guardian']);
        // non-system pending write is permitted (the ceremonies still create pending rows)
        $this->under($guardian, fn () => DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id,
            'status' => 'pending_confirmation', 'origin' => 'parent_initiated', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertSame(1, $this->sys(fn () => DB::table('guardian_links')->count()));
        // system writes active freely (the sanctioned elevated paths)
        $this->sys(fn () => DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => User::factory()->create(['role' => 'guardian'])->id,
            'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertSame(1, $this->sys(fn () => DB::table('guardian_links')->where('status', 'active')->count()));
    }

    // ── assertion teeth ────────────────────────────────────────────────────────

    public function test_guardian_addition_visibility_reds_on_a_silent_addition_then_greens(): void
    {
        $school = $this->school();
        [$student, $g1] = $this->studentWithGuardian($school);
        $g2 = User::factory()->create(['role' => 'guardian']);
        // a SECOND active guardian_link (created after G1) with NO visibility record → silent → RED
        $glId = (string) Str::uuid7();
        $this->sys(fn () => DB::table('guardian_links')->insert(['id' => $glId, 'student_id' => $student->id, 'guardian_id' => $g2->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]));
        $this->sys(fn () => DB::table('audit_events')->insert(['event_id' => (string) Str::uuid7(), 'occurred_at' => now(), 'entity_type' => 'guardian_link', 'entity_id' => $glId, 'action' => 'guardian_link.created', 'to_state' => 'active', 'actor_role' => 'system', 'request_id' => (string) Str::uuid7()]));
        $this->assertFalse($this->sys(fn () => (new GuardianAdditionVisibilityAssertion)->check()->passed), 'a silent addition must RED');
        // add the visibility record → green
        $this->sys(fn () => DB::table('link_visibility_events')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'new_guardian_id' => $g2->id, 'new_link_id' => $glId, 'addressed_guardian_id' => $g1->id, 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertTrue($this->sys(fn () => (new GuardianAdditionVisibilityAssertion)->check()->passed));
    }

    public function test_vouch_scope_reds_on_a_rollless_vouch_then_greens(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $guardian = User::factory()->create(['role' => 'guardian']);
        $school = $this->school();
        // a vouched (school_mediated) active link whose student is on NO roll → RED
        $this->sys(fn () => DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'school_mediated', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertFalse($this->sys(fn () => (new VouchScopeAssertion)->check()->passed), 'a vouch for a student on no roll must RED');
        // put the student on a roll → green
        $this->sys(fn () => DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertTrue($this->sys(fn () => (new VouchScopeAssertion)->check()->passed));
    }
}
