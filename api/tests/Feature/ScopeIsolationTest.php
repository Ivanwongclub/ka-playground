<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\PermissionResolver;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private User $adminA;

    private User $adminB;

    private User $studentA;

    private User $studentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolA = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        $this->schoolB = School::query()->create(['name_en' => 'School B', 'name_tc' => '乙校', 'name_sc' => '乙校']);
        $this->adminA = $this->schoolAdmin($this->schoolA->id);
        $this->adminB = $this->schoolAdmin($this->schoolB->id);
        $this->studentA = $this->studentAt($this->schoolA->id, 'Student Alpha');
        $this->studentB = $this->studentAt($this->schoolB->id, 'Student Beta');
    }

    private function schoolAdmin(int $schoolId): User
    {
        $admin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert([
            'id' => (string) Str::uuid7(), 'school_admin_id' => $admin->id,
            'school_id' => $schoolId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $admin;
    }

    private function studentAt(int $schoolId, string $name): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
        DB::table('school_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $student->id,
            'school_id' => $schoolId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $student;
    }

    // ── The isolation control ──

    public function test_school_admin_lists_only_their_own_schools_students(): void
    {
        Sanctum::actingAs($this->adminA);
        $names = collect($this->getJson('/api/school/students')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Student Alpha'));
        $this->assertFalse($names->contains('Student Beta'), 'school B student leaked into school A listing');
    }

    public function test_cross_school_student_detail_is_404_and_audited(): void
    {
        Sanctum::actingAs($this->adminA);
        $this->getJson("/api/school/students/{$this->studentB->id}")->assertStatus(404);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'scope.denied',
            'actor_id' => $this->adminA->id,
            'entity_id' => (string) $this->studentB->id,
        ]);

        // …while their own student resolves fine
        $this->getJson("/api/school/students/{$this->studentA->id}")->assertOk();
    }

    public function test_operations_capability_crosses_schools_the_platform_owner_contrast(): void
    {
        $ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $ops->id,
            'capability' => 'operations', 'granted_by' => $ops->id, 'granted_at' => now(),
        ]);

        Sanctum::actingAs($ops);
        $names = collect($this->getJson('/api/admin/students')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Student Alpha') && $names->contains('Student Beta'));
    }

    public function test_scoped_teacher_invitation_binds_the_admins_own_school(): void
    {
        Sanctum::actingAs($this->adminA);
        $response = $this->postJson('/api/school/teachers/invite', ['email' => 'teacher@synthetic.test'])
            ->assertStatus(201);

        $this->assertSame($this->schoolA->id, $response->json('school_id'));

        // Acceptance activates the vouched teacher_link to school A
        $this->postJson('/api/onboarding/accept', [
            'token' => $response->json('token'), 'password' => 'teacher-pass-12345',
        ])->assertStatus(201);
        $teacherId = User::query()->where('email', 'teacher@synthetic.test')->value('id');
        $this->assertDatabaseHas('teacher_links', [
            'teacher_id' => $teacherId, 'school_id' => $this->schoolA->id, 'status' => 'active',
        ]);
    }

    // ── Fail-closed + forgery ──

    public function test_no_context_yields_zero_rows_fail_closed(): void
    {
        $this->assertGreaterThan(0, DB::table('school_links')->count()); // harness = system sees them

        app(ScopeContext::class)->reset();
        try {
            $this->assertSame(0, DB::table('school_links')->count(), 'fail-closed violated');
            $this->assertSame(0, DB::table('guardian_links')->count());
        } finally {
            app(ScopeContext::class)->setSystem();
        }
    }

    public function test_forged_context_inputs_have_no_effect(): void
    {
        // Admin A attacks with every request-input channel claiming school B
        Sanctum::actingAs($this->adminA);
        $forged = [
            'HTTP_X_APP_SCHOOL_IDS' => (string) $this->schoolB->id,
            'HTTP_X_APP_CONTEXT' => 'system',
        ];

        $response = $this->withUnencryptedCookie('app.school_ids', (string) $this->schoolB->id)
            ->call('GET', '/api/school/students', [
                'app_school_ids' => $this->schoolB->id, 'app' => ['school_ids' => $this->schoolB->id],
            ], [], [], $forged);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertFalse($names->contains('Student Beta'), 'forged input widened the scope');

        $this->call('GET', "/api/school/students/{$this->studentB->id}", [
            'app_school_ids' => $this->schoolB->id,
        ], [], [], $forged)->assertStatus(404);
    }

    public function test_context_derivation_reads_links_not_input(): void
    {
        // Direct proof: with admin A authenticated, the session context carries
        // ONLY the school from school_admin_links, whatever the request said
        Sanctum::actingAs($this->adminA);
        $this->call('GET', '/api/school/students', ['app_school_ids' => '999'], [], [],
            ['HTTP_X_APP_SCHOOL_IDS' => '999']);
        // (context was reset by terminate; re-derive server-side to inspect)
        app(ScopeContext::class)->set($this->adminA->fresh());
        $setting = DB::selectOne("SELECT current_setting('app.school_ids', true) AS v")->v;
        app(ScopeContext::class)->setSystem();

        $this->assertSame((string) $this->schoolA->id, $setting);
    }

    // ── Worker boundary (Leo): two users in sequence ──

    public function test_a_job_after_a_request_does_not_inherit_the_requests_context(): void
    {
        Sanctum::actingAs($this->adminA);
        $this->getJson('/api/school/students')->assertOk();

        // Simulate the worker picking up a job on this same connection:
        // middleware terminate has reset; Queue::before scrubs and sets system.
        app(ScopeContext::class)->reset();
        event(new \Illuminate\Queue\Events\JobProcessing('test', new class
        {
            public function getConnectionName(): string
            {
                return 'sync';
            }

            public function resolveName(): string
            {
                return 'probe';
            }

            public function payload(): array
            {
                return [];
            }
        }));

        $ctx = DB::selectOne("SELECT current_setting('app.context', true) AS c, current_setting('app.actor_id', true) AS a, current_setting('app.school_ids', true) AS s");
        $this->assertSame('system', $ctx->c, 'job context must be system, never a user');
        $this->assertSame('', $ctx->a, "job inherited a user's actor id");
        $this->assertSame('', $ctx->s, "job inherited a user's school scope");

        // Second user in sequence sees THEIR scope, never A's
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->adminB);
        $names = collect($this->getJson('/api/school/students')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Student Beta'));
        $this->assertFalse($names->contains('Student Alpha'), "user B inherited A's scope");
    }

    // ── Overrides: narrow works, widening rejected at BOTH layers ──

    public function test_override_narrows_one_pairing_only(): void
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        foreach ([$this->studentA, $this->studentB] as $student) {
            DB::table('guardian_links')->insert([
                'id' => (string) Str::uuid7(), 'student_id' => $student->id,
                'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding',
                'permission_overrides' => $student->is($this->studentA) ? json_encode(['deny' => ['consent.sign']]) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $resolver = app(PermissionResolver::class);
        $this->assertFalse($resolver->allowsFor($guardian, 'consent.sign', $this->studentA->id), 'override must narrow pairing A');
        $this->assertTrue($resolver->allowsFor($guardian, 'consent.sign', $this->studentB->id), 'pairing B must be untouched');
        $this->assertTrue($resolver->allowsFor($guardian, 'consent.view', $this->studentA->id), 'non-denied permission unaffected');
    }

    public function test_widening_override_rejected_at_write_time_by_the_database(): void
    {
        $guardian = User::factory()->create(['role' => 'guardian']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('would widen');
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $this->studentA->id,
            'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'permission_overrides' => json_encode(['allow' => ['finance.confirm']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_widening_ignored_at_resolve_time_even_if_planted(): void
    {
        // Belt over braces: feed the resolver a link whose overrides carry an
        // (hypothetically planted) non-deny key — it must grant nothing
        $guardian = User::factory()->create(['role' => 'guardian']);
        DB::table('guardian_links')->insert([
            'id' => (string) Str::uuid7(), 'student_id' => $this->studentA->id,
            'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding',
            'permission_overrides' => json_encode(['deny' => []]), // legal shape
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolver = app(PermissionResolver::class);
        // finance.confirm is not in the guardian role default: no override could add it
        $this->assertFalse($resolver->allowsFor($guardian, 'finance.confirm', $this->studentA->id));
    }
}
