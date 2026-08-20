<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Consent\ConsentSigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-3b STEP 1 — B2 the member-readable roster (GET /teams/{team}/members) + the student self-consent
 * read. B2 is child-safety-adjacent: a member sees teammate NAMES + role + count via an allowlisted
 * elevation (tm_read walls a student off from co-members), but NEVER consent / guardian / enrolment /
 * money. A non-member of a joinable lobby team gets a COUNT ONLY, never names.
 */
class TeamMembersRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $memberA;      // active member, holds the Captain role

    private User $memberB;      // active member

    private User $guardianA;    // guardian of memberA (signed → memberA consent satisfied)

    private User $joinable;     // non-member with an in_pool enrolment in the lobby → sees a COUNT only

    private User $outsider;     // non-member, no enrolment → 404

    private int $programmeId;

    private string $teamId;

    private string $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = $this->admin(['operations']);
        [$school] = $this->schoolWithAdmin();
        $this->memberA = User::factory()->create(['role' => 'student', 'name' => 'Alpha Member']);
        $this->memberB = User::factory()->create(['role' => 'student', 'name' => 'Bravo Member']);
        $this->guardianA = User::factory()->create(['role' => 'guardian', 'name' => 'Gia Guardian']);
        $this->joinable = User::factory()->create(['role' => 'student', 'name' => 'Joan Joinable']);
        $this->outsider = User::factory()->create(['role' => 'student', 'name' => 'Otto Outsider']);

        [$this->programmeId, $this->teamId, $this->templateId] = $this->sys(function () {
            $pid = DB::table('programmes')->insertGetId(['code' => 'RB'.Str::random(4), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            $templateId = (string) Str::uuid7();
            DB::table('consent_templates')->insert(['id' => $templateId, 'name_en' => 'T', 'name_tc' => 'T', 'name_sc' => 'T', 'created_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now()]);
            // requires_all = false (any-one guardian) so one signed guardian satisfies memberA
            DB::table('wizard_sections')->insert(['id' => (string) Str::uuid7(), 'programme_id' => $pid, 'section_key' => 'consent', 'status' => 'complete', 'data' => json_encode(['requires_all_guardians' => false, 'template_ref' => $templateId]), 'created_at' => now(), 'updated_at' => now()]);
            // an OPEN lobby (school_id NULL) so any student with an in_pool enrolment sees the forming team
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $pid, 'name_en' => 'Open', 'name_tc' => 'Open', 'name_sc' => 'Open', 'assignment_rule' => 'open', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $roleId = (string) Str::uuid7();
            DB::table('role_library')->insert(['id' => $roleId, 'programme_id' => $pid, 'name_en' => 'Captain', 'name_tc' => '隊長', 'name_sc' => '队长', 'min_holders' => 1, 'max_holders' => 1, 'mandatory' => true, 'created_at' => now(), 'updated_at' => now()]);

            $teamId = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $teamId, 'programme_id' => $pid, 'category_id' => $lobby, 'name' => 'Alpha Team', 'status' => 'forming', 'created_by' => $this->memberA->id, 'created_at' => now(), 'updated_at' => now()]);

            $mk = function (User $stu, string $status) use ($pid) {
                $e = (string) Str::uuid7();
                DB::table('enrolments')->insert(['id' => $e, 'programme_id' => $pid, 'student_id' => $stu->id, 'acting_guardian_id' => $this->guardianA->id, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

                return $e;
            };
            $eA = $mk($this->memberA, 'teamed');
            $eB = $mk($this->memberB, 'teamed');
            $mk($this->joinable, 'in_pool'); // unteamed pooled → sees the forming team via lobbyWall
            foreach ([[$this->memberA, $eA], [$this->memberB, $eB]] as [$stu, $e]) {
                DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $teamId, 'programme_id' => $pid, 'enrolment_id' => $e, 'category_id' => $lobby, 'student_id' => $stu->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            // memberA holds Captain
            DB::table('tenures')->insert(['id' => (string) Str::uuid7(), 'team_id' => $teamId, 'role_id' => $roleId, 'category_id' => $lobby, 'enrolment_id' => $eA, 'student_id' => $this->memberA->id, 'state' => 'active', 'started_at' => now(), 'assigned_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now()]);
            // memberA's guardian + a SIGNED consent → memberA satisfied
            DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->memberA->id, 'guardian_id' => $this->guardianA->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('consent_requests')->insert(['id' => (string) Str::uuid7(), 'template_id' => $templateId, 'programme_id' => $pid, 'student_id' => $this->memberA->id, 'signer_id' => $this->guardianA->id, 'status' => 'signed', 'merge_data' => json_encode([]), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

            return [$pid, $teamId, $templateId];
        });
    }

    // ── (1) Privacy tooth — exact key allowlist, no consent/guardian/enrolment/money ────────────────
    public function test_roster_carries_only_names_role_count_no_consent_or_family_or_money(): void
    {
        $this->act($this->memberA);
        $res = $this->getJson("/api/teams/{$this->teamId}/members")->assertOk();
        $raw = $res->getContent();

        // exact key allowlist (top-level + per-member)
        $this->assertEqualsCanonicalizing(['team_id', 'member_count', 'members'], array_keys($res->json()));
        $this->assertEqualsCanonicalizing(['student_id', 'student_name', 'role'], array_keys($res->json('members.0')));

        // NOTHING consent / guardian / enrolment / money leaves the elevation
        foreach (['consent', 'satisfied', 'guardian', 'signer', 'signed', 'enrolment', 'obligation', 'payer', 'amount', 'blocker'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $raw, "roster must not leak '{$forbidden}'");
        }
        // names + role are present (the intended widening)
        $this->assertEqualsCanonicalizing(['Alpha Member', 'Bravo Member'], collect($res->json('members'))->pluck('student_name')->all());
        $this->assertSame('Captain', collect($res->json('members'))->firstWhere('student_id', $this->memberA->id)['role']['name_en']);
    }

    // ── (2) Five-branch authority ───────────────────────────────────────────────────────────────────
    public function test_authority_is_five_branch(): void
    {
        // member → names
        $this->act($this->memberA);
        $this->getJson("/api/teams/{$this->teamId}/members")->assertOk()->assertJsonPath('members.0.student_name', fn ($n) => $n !== null);

        // guardian of a member → names
        $this->act($this->guardianA);
        $this->assertNotNull($this->getJson("/api/teams/{$this->teamId}/members")->assertOk()->json('members'));

        // ops → names
        $this->act($this->ops);
        $this->assertNotNull($this->getJson("/api/teams/{$this->teamId}/members")->assertOk()->json('members'));

        // non-member of a JOINABLE lobby team → COUNT ONLY, never names
        $this->act($this->joinable);
        $j = $this->getJson("/api/teams/{$this->teamId}/members")->assertOk()->json();
        $this->assertNull($j['members'], 'a joinable non-member gets no names');
        $this->assertSame(2, $j['member_count']);
        $this->assertStringNotContainsString('Alpha Member', $this->getJson("/api/teams/{$this->teamId}/members")->getContent());

        // non-member, non-joinable (no enrolment) → 404
        $this->act($this->outsider);
        $this->getJson("/api/teams/{$this->teamId}/members")->assertNotFound();
    }

    // ── (3) Count correctness — B2 elevated count is TRUE where the student's B1 undercounts ────────
    public function test_b2_count_is_true_where_b1_undercounts_for_a_student(): void
    {
        $this->act($this->memberA);
        // B2: the true count (2), via the elevation
        $this->assertSame(2, $this->getJson("/api/teams/{$this->teamId}/members")->assertOk()->json('member_count'));
        // B1 (GET /teams) counts team_members under the student's RLS (tm_read = own row only) → undercounts to 1
        $b1 = collect($this->getJson('/api/teams')->assertOk()->json('data'))->firstWhere('id', $this->teamId);
        $this->assertSame(1, $b1['member_count'], 'B1 undercounts for a student — which is exactly why B2 needs the elevation');
    }

    // ── (5) student self-consent read — self-scoped, no elevation ────────────────────────────────────
    public function test_student_reads_only_their_own_consent_satisfied(): void
    {
        $authoritative = $this->sys(fn () => app(ConsentSigningService::class)->consentSatisfied($this->programmeId, $this->memberA->id));

        // memberA reads THEIR OWN satisfied (signed guardian → true)
        $this->act($this->memberA);
        $mine = $this->getJson('/api/my/consent-status?programme_id='.$this->programmeId)->assertOk()->json();
        $this->assertEqualsCanonicalizing(['satisfied'], array_keys($mine));
        $this->assertSame($authoritative, $mine['satisfied']);
        $this->assertTrue($mine['satisfied']);

        // memberB (no signed guardian) reads THEIR OWN — the endpoint is self-only (no id param to name
        // another student), so memberB can never read memberA's status.
        $this->act($this->memberB);
        $this->assertFalse($this->getJson('/api/my/consent-status?programme_id='.$this->programmeId)->assertOk()->json('satisfied'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────
    private function admin(array $caps): User
    {
        $u = User::factory()->create(['role' => 'academy_admin']);
        foreach ($caps as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $u->id, 'capability' => $c, 'granted_by' => $u->id, 'granted_at' => now()]);
        }

        return $u;
    }

    /** @return array{0: School, 1: User} */
    private function schoolWithAdmin(): array
    {
        $school = School::query()->create(['name_en' => 'S'.Str::random(3), 'name_tc' => 'S', 'name_sc' => 'S']);
        $admin = User::factory()->create(['role' => 'school_admin']);
        $this->sys(fn () => DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $admin->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        return [$school, $admin];
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
}
