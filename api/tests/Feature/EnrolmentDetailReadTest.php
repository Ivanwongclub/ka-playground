<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-READ-1 — RLS PROOF (RIDER-1) for GET /api/enrolments/{id}.
 * The detail read is the index() query narrowed by id under the SAME enr_read RLS. This proves, empirically per
 * seeded role, that an enrolment outside the viewer's scope returns 404 (never 403 from a RLS denial, never a
 * partial body), while the list read is byte-identical (no detail-only field leaks into it).
 *   enr_read = system OR opsAudit(academy_admin + operations|audit_read|super_admin) OR student_id=actor
 *              OR student_id=ANY(guardian's children) OR school_admin's active-roll students.
 * So: student/guardian/school_admin reach their own only (out-of-scope → 404); ops/audit/super reach ALL (no
 * 404 by design); teacher/finance-only/member reach NONE — they are denied (403 at permission:enrolment.view
 * if they lack it, else 404 at RLS; either way no 200, no body).
 */
class EnrolmentDetailReadTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;
    private School $schoolB;
    private Programme $programme;
    private User $studentA;
    private User $studentB;
    private User $guardianA;
    private User $guardianB;
    private User $schoolAdminA;
    private string $enrolA; // studentA @ schoolA
    private string $enrolB; // studentB @ schoolB

    protected function setUp(): void
    {
        parent::setUp(); // harness system context — setup inserts bypass RLS legitimately
        $this->schoolA = School::query()->create(['name_en' => 'School A', 'name_tc' => '甲校', 'name_sc' => '甲校']);
        $this->schoolB = School::query()->create(['name_en' => 'School B', 'name_tc' => '乙校', 'name_sc' => '乙校']);
        $this->programme = Programme::query()->create([
            'code' => 'DET-'.Str::upper(Str::random(4)), 'name_en' => 'Detail P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK',
        ]);
        $this->studentA = $this->studentAt($this->schoolA->id);
        $this->studentB = $this->studentAt($this->schoolB->id);
        $this->guardianA = $this->guardianOf($this->studentA->id);
        $this->guardianB = $this->guardianOf($this->studentB->id);
        $this->schoolAdminA = $this->schoolAdmin($this->schoolA->id);
        $this->enrolA = $this->enrolment($this->studentA->id, $this->guardianA->id);
        $this->enrolB = $this->enrolment($this->studentB->id, $this->guardianB->id);
    }

    private function studentAt(int $schoolId): User
    {
        $s = User::factory()->create(['role' => 'student']);
        DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $s->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $s;
    }

    private function guardianOf(int $studentId): User
    {
        $g = User::factory()->create(['role' => 'guardian']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $studentId, 'guardian_id' => $g->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);

        return $g;
    }

    private function schoolAdmin(int $schoolId): User
    {
        $a = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $a->id, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $a;
    }

    private function academyAdmin(string $capability): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $capability, 'granted_by' => $u->id, 'granted_at' => now()]);

        return $u;
    }

    private function enrolment(int $studentId, int $guardianId): string
    {
        $id = (string) Str::uuid7();
        DB::table('enrolments')->insert(['id' => $id, 'programme_id' => $this->programme->id, 'student_id' => $studentId, 'acting_guardian_id' => $guardianId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** A denial for a role that reaches NO enrolment: never 200, never a body — 403 (no permission) or 404 (RLS). */
    private function assertDenied(string $enrolId): void
    {
        $r = $this->getJson("/api/enrolments/{$enrolId}");
        $this->assertContains($r->status(), [403, 404], "expected a denial, got {$r->status()}");
        $this->assertNull($r->json('id'), 'a denied detail must not leak a partial enrolment body');
    }

    // ── the 404-not-403 proofs: a viewer WITH enrolment.view, RLS denies the row ──

    public function test_student_reads_own_and_404s_another(): void
    {
        Sanctum::actingAs($this->studentA);
        $this->getJson("/api/enrolments/{$this->enrolA}")->assertOk();
        $r = $this->getJson("/api/enrolments/{$this->enrolB}");
        $r->assertStatus(404); // has enrolment.view, RLS denies → 404, never 403, never partial
        $this->assertNull($r->json('id'));
    }

    public function test_guardian_reads_child_and_404s_cross_family(): void
    {
        Sanctum::actingAs($this->guardianA);
        $this->getJson("/api/enrolments/{$this->enrolA}")->assertOk();          // own child
        $this->getJson("/api/enrolments/{$this->enrolB}")->assertStatus(404);   // guardian B's child — CROSS-FAMILY
    }

    public function test_school_admin_reads_roll_and_404s_cross_school(): void
    {
        Sanctum::actingAs($this->schoolAdminA);
        $this->getJson("/api/enrolments/{$this->enrolA}")->assertOk();          // school A student
        $this->getJson("/api/enrolments/{$this->enrolB}")->assertStatus(404);   // school B student — CROSS-SCHOOL
    }

    // ── reach-all roles: no 404, by design (opsAudit arm) ──

    public function test_ops_audit_super_read_every_enrolment(): void
    {
        foreach (['operations', 'audit_read', 'super_admin'] as $cap) {
            $u = $this->academyAdmin($cap);
            Sanctum::actingAs($u);
            $this->getJson("/api/enrolments/{$this->enrolA}")->assertOk();
            $this->getJson("/api/enrolments/{$this->enrolB}")->assertOk(); // reads all — no out-of-scope
            $this->app['auth']->forgetGuards();
        }
    }

    // ── reach-none roles: denied (403 or 404), never a body ──

    public function test_teacher_finance_member_reach_no_enrolment(): void
    {
        $roles = [User::factory()->create(['role' => 'teacher']), $this->academyAdmin('finance'), User::factory()->create(['role' => 'member'])];
        foreach ($roles as $u) {
            Sanctum::actingAs($u);
            $this->assertDenied($this->enrolA);
            $this->app['auth']->forgetGuards();
        }
    }

    // ── the added fields are present; dropped fields are absent ──

    public function test_owner_detail_carries_added_fields_and_omits_dropped_ones(): void
    {
        Sanctum::actingAs($this->studentA);
        $r = $this->getJson("/api/enrolments/{$this->enrolA}")->assertOk();
        $r->assertJsonStructure(['id', 'programme_id', 'student_id', 'status', 'created_at', 'programme_name_en', 'programme_name_tc', 'programme_name_sc', 'banner_url', 'team_id', 'team_name']);
        $this->assertArrayNotHasKey('member_count', $r->json());        // DROPPED — no teammate rows
        $this->assertArrayNotHasKey('transitions', $r->json());          // DROPPED — audit_read-gated
        $this->assertNull($r->json('team_id'));                          // studentA is in no team → null (not an error)
    }

    // ── the list read is byte-identical: no detail-only field leaks into it ──

    public function test_list_read_is_unchanged_no_detail_fields_leak(): void
    {
        Sanctum::actingAs($this->studentA);
        $row = $this->getJson('/api/enrolments')->assertOk()->json('data.0');
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayNotHasKey('team_id', $row, 'detail-only field leaked into the list read');
        $this->assertArrayNotHasKey('banner_url', $row, 'detail-only field leaked into the list read');
    }
}
