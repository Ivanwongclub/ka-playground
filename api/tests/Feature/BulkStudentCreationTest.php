<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use App\Services\Reconciliation\Assertions\AccountProvenanceAssertion;
use App\Services\Reconciliation\Assertions\NoActiveWithoutApprovalAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S04D STEP 4 — bulk student creation. Row-by-row with a full report (never a
 * silent partial), per-row roll authority, idempotent, minted via
 * AccountMintingService (born unverified + token), active school_links audited.
 */
class BulkStudentCreationTest extends TestCase
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

    private function school(): School
    {
        return $this->sys(fn () => School::query()->create(['name_en' => 'S'.Str::random(3), 'name_tc' => '甲', 'name_sc' => '甲']));
    }

    private function schoolAdmin(int $schoolId): User
    {
        $u = User::factory()->create(['role' => 'school_admin']);
        $this->sys(function () use ($u, $schoolId) {
            DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $u->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            // give the fixture admin its own provenance so the global account.provenance
            // assertion measures the bulk service, not the factory shortcut.
            app(AuditService::class)->record('user', $u->id, 'user.created', toState: 'registered', payloadAfter: ['origin' => 'test_fixture'], actor: $u);
        });

        return $u;
    }

    private function bulk(User $admin, array $rows): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);

        return $this->postJson('/api/school/bulk-students', ['rows' => $rows])->assertStatus(201)->json();
    }

    // ── happy path: minted unverified + token, active school_link audited ──────

    public function test_bulk_creates_unverified_students_with_active_audited_school_links(): void
    {
        $school = $this->school();
        $admin = $this->schoolAdmin($school->id);

        $report = $this->bulk($admin, [
            ['name' => 'Ann', 'email' => 'ann@example.com', 'school_id' => $school->id],
            ['name' => 'Ben', 'email' => 'ben@example.com', 'school_id' => $school->id],
        ]);
        $this->assertCount(2, $report['created']);

        $ann = User::where('email', 'ann@example.com')->first();
        $this->assertSame('student', $ann->role);
        $this->assertFalse($ann->hasVerifiedEmail(), 'born UNVERIFIED (OD-29 bulk clause)');
        $this->assertNotNull($this->sys(fn () => DB::table('users')->where('id', $ann->id)->value('activation_token_hash')), 'minted with an activation token (mintPendingActivation)');
        // pre-activation login refused — no usable password
        $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'guess'])->assertStatus(422);

        // active school_link (origin=bulk) + its to_state='active' audit
        $sl = $this->sys(fn () => DB::table('school_links')->where('student_id', $ann->id)->where('status', 'active')->first());
        $this->assertSame('bulk', $sl->origin);
        $this->assertSame(1, $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'school_link')->where('entity_id', $sl->id)->where('to_state', 'active')->count()));
        // provenance + no-active-without-approval stay green
        $this->assertTrue($this->sys(fn () => (new NoActiveWithoutApprovalAssertion)->check()->passed));
        $this->assertTrue($this->sys(fn () => (new AccountProvenanceAssertion)->check()->passed));
    }

    // ── the DEFINED failure mode: row-by-row report, never a silent partial ────

    public function test_mid_batch_failure_is_reported_row_by_row_never_silent(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school(); // a school the admin does NOT administer
        $admin = $this->schoolAdmin($schoolA->id);
        // an already-existing account
        $this->sys(fn () => User::factory()->create(['role' => 'student', 'email' => 'exists@example.com']));

        $report = $this->bulk($admin, [
            ['name' => 'Good', 'email' => 'good@example.com', 'school_id' => $schoolA->id],   // created
            ['name' => 'Wrong', 'email' => 'wrong@example.com', 'school_id' => $schoolB->id], // rejected — not your school
            ['name' => 'Dup', 'email' => 'exists@example.com', 'school_id' => $schoolA->id],  // skipped — already exists
        ]);

        // every row is accounted for — no silent partial
        $this->assertSame(['good@example.com'], array_column($report['created'], 'email'));
        $this->assertSame(['wrong@example.com'], array_column($report['rejected'], 'email'));
        $this->assertSame(['exists@example.com'], array_column($report['skipped'], 'email'));
        $this->assertStringContainsString('roll authority', $report['rejected'][0]['reason']);
        // the good row IS created (row-by-row, not all-or-nothing); the wrong-school one is NOT
        $this->assertTrue(User::where('email', 'good@example.com')->exists());
        $this->assertFalse(User::where('email', 'wrong@example.com')->exists());
        // the batch itself is recorded (persistent proof)
        $this->assertSame(1, $this->sys(fn () => DB::table('audit_events')->where('action', 'bulk.students_created')->where('actor_id', $admin->id)->count()));
    }

    // ── idempotency: a re-uploaded batch does not duplicate ───────────────────

    public function test_reuploading_the_same_batch_skips_and_does_not_duplicate(): void
    {
        $school = $this->school();
        $admin = $this->schoolAdmin($school->id);
        $rows = [['name' => 'Cara', 'email' => 'cara@example.com', 'school_id' => $school->id]];

        $first = $this->bulk($admin, $rows);
        $this->assertCount(1, $first['created']);
        $second = $this->bulk($admin, $rows); // same batch again
        $this->assertCount(0, $second['created']);
        $this->assertSame(['cara@example.com'], array_column($second['skipped'], 'email'));
        // exactly one account, one active school_link — no duplicate
        $this->assertSame(1, $this->sys(fn () => DB::table('users')->where('email', 'cara@example.com')->count()));
        $this->assertSame(1, $this->sys(fn () => DB::table('school_links')->where('student_id', User::where('email', 'cara@example.com')->value('id'))->count()));
    }

    // ── the endpoint is school-admin only ─────────────────────────────────────

    public function test_a_non_school_admin_cannot_bulk_create(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'guardian']));
        $this->postJson('/api/school/bulk-students', ['rows' => [['name' => 'X', 'email' => 'x@example.com', 'school_id' => 1]]])->assertStatus(403);
    }
}
