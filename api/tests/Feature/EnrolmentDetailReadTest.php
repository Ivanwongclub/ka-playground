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

    // ── reach-all roles: no 404, by design (opsAudit RLS arm) ──

    // operations + super_admin hold enrolment.view (permission matrix), so the middleware admits them and the
    // enrolments RLS opsAudit arm reaches EVERY enrolment — no out-of-scope 404 for these two.
    public function test_operations_and_super_read_every_enrolment(): void
    {
        foreach (['operations', 'super_admin'] as $cap) {
            $u = $this->academyAdmin($cap);
            Sanctum::actingAs($u);
            $this->getJson("/api/enrolments/{$this->enrolA}")->assertOk();
            $this->getJson("/api/enrolments/{$this->enrolB}")->assertOk(); // reads all — no out-of-scope
            $this->app['auth']->forgetGuards();
        }
    }

    // audit_read grants audit.read — the TRAIL, not the domain — so an audit-only academy_admin has NO
    // enrolment.view and is DENIED at the permission:enrolment.view middleware BEFORE RLS, on BOTH endpoints.
    // This is a CORRECT denial (a 403 gate), not a weakened one — asserted explicitly, not folded into
    // assertDenied, precisely because the reason matters.
    //
    // VESTIGIAL ARM (pre-existing, NOT introduced here; a backlog policy-hygiene item, separate ruling): the
    // enrolments RLS opsAudit arm includes `'audit_read' = ANY(caps)`, but the matrix never grants audit_read
    // enrolment.view — so that arm is UNREACHABLE for an audit-only admin, on the list read exactly as on the
    // detail read. Either the arm is dead code to remove, or audit is meant to reach enrolments and the matrix
    // is missing a grant. Not resolved here.
    public function test_audit_read_is_denied_at_the_permission_gate(): void
    {
        Sanctum::actingAs($this->academyAdmin('audit_read'));
        foreach ([$this->enrolA, $this->enrolB] as $id) {
            $r = $this->getJson("/api/enrolments/{$id}");
            $r->assertStatus(403);              // permission:enrolment.view — audit.read is the trail, not the domain
            $this->assertNull($r->json('id'));  // no body on a denial
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

    // ── S-READ-2: the list read is WIDENED — SAME ROWS, columns only, and NEVER an amount ──

    public function test_list_widened_same_rows_new_columns_no_amount(): void
    {
        Sanctum::actingAs($this->studentA);
        $data = $this->getJson('/api/enrolments')->assertOk()->json('data');
        // SAME ROWS (behaviour-sha): the student still sees only their own enrolment — enr_read scope unchanged,
        // scalar subqueries cannot add/remove rows.
        $this->assertSame([$this->enrolA], array_column($data, 'id'), 'the widened list changed which rows return');
        $row = $data[0];
        foreach (['banner_url', 'team_name', 'consent_status', 'consent_expires_at', 'school_name_en', 'school_name_tc', 'school_name_sc', 'next_session_title', 'next_session_starts_at'] as $col) {
            $this->assertArrayHasKey($col, $row, "widened column {$col} is missing");
        }
        // A student must NEVER receive an order amount — least of all on a read shared with the guardian (P-3/B-18).
        foreach (['amount', 'total_amount_minor', 'total', 'fee', 'order_amount', 'due_at'] as $amt) {
            $this->assertArrayNotHasKey($amt, $row, "amount field {$amt} leaked into the shared list read");
        }
        $this->assertArrayNotHasKey('member_count', $row); // DROPPED — counting teammates is a new visibility path
    }

    public function test_list_cross_family_and_cross_school_unchanged(): void
    {
        // Guardian A → only their own child's enrolment, never guardian B's child (cross-family).
        Sanctum::actingAs($this->guardianA);
        $this->assertSame([$this->enrolA], array_column($this->getJson('/api/enrolments')->assertOk()->json('data'), 'id'));
        $this->app['auth']->forgetGuards();
        // School admin A → only school A's student, never school B's (cross-school).
        Sanctum::actingAs($this->schoolAdminA);
        $this->assertSame([$this->enrolA], array_column($this->getJson('/api/enrolments')->assertOk()->json('data'), 'id'));
    }
}
