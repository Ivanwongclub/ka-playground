<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\AuthorityGrantService;
use App\Services\Authz\EffectiveCapabilityResolver;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A-3 — the effective-capability resolver + ScopeContext derivation. Proves: baseline grants surface in the
 * request-wide GUC; the withhold DIVERGENCE (GUC keeps a cap request-wide, per-programme drops it); school-
 * specific > all-schools precedence; never-caps are filtered (defense-in-depth); a school with no grants gets
 * nothing; the caps land in app.capabilities; and the academy_admin branch is unchanged (behaviour-sha).
 */
class EffectiveCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;    // a school_admin of $school

    private User $platform; // the grantor

    private int $programmeX;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::query()->create(['name_en' => 'School', 'name_tc' => '校', 'name_sc' => '校']);
        $this->admin = $this->schoolAdmin($this->school->id);
        $this->platform = User::factory()->create(['role' => 'academy_admin']);
        $this->programmeX = $this->makeProgramme();
    }

    private function schoolAdmin(int $schoolId): User
    {
        $admin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert([
            'id' => (string) Str::uuid7(), 'school_admin_id' => $admin->id,
            'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $admin;
    }

    private function makeProgramme(): int
    {
        return DB::table('programmes')->insertGetId([
            'code' => 'A3-'.Str::upper(Str::random(6)), 'name_en' => 'P', 'name_tc' => '課', 'name_sc' => '课',
            'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'draft', 'is_template' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resolver(): EffectiveCapabilityResolver
    {
        return app(EffectiveCapabilityResolver::class);
    }

    private function grants(): AuthorityGrantService
    {
        return app(AuthorityGrantService::class);
    }

    public function test_baseline_grants_surface_in_the_request_wide_guc(): void
    {
        $this->grants()->grant($this->platform, $this->school->id, 'teams.approve');
        $this->grants()->grant($this->platform, $this->school->id, 'enrolment.create');

        $this->assertEqualsCanonicalizing(
            ['teams.approve', 'enrolment.create'],
            $this->resolver()->capabilitiesForGuc($this->admin),
        );
    }

    public function test_a_school_with_no_grants_gets_zero_caps(): void
    {
        $this->assertSame([], $this->resolver()->capabilitiesForGuc($this->admin));
    }

    /** THE DIVERGENCE (A-3 ruling 5): a withhold is honored ONLY per-programme, never by the request-wide GUC. */
    public function test_withhold_divergence_guc_keeps_it_but_per_programme_drops_it(): void
    {
        $this->grants()->grant($this->platform, $this->school->id, 'teams.approve');
        $this->grants()->setOverride($this->platform, $this->programmeX, $this->school->id, 'teams.approve', 'withhold');

        // request-wide GUC CONTAINS it (superset — the design, so nobody "fixes" the GUC to subtract withholds)
        $this->assertContains('teams.approve', $this->resolver()->capabilitiesForGuc($this->admin));
        // the per-programme truth EXCLUDES it for X — the sole source A-4 will enforce against
        $this->assertNotContains('teams.approve', $this->resolver()->capabilitiesForProgramme($this->admin, $this->programmeX));
    }

    public function test_grant_override_adds_a_cap_for_its_programme_only(): void
    {
        $this->grants()->setOverride($this->platform, $this->programmeX, $this->school->id, 'enrolment.create', 'grant');

        $this->assertContains('enrolment.create', $this->resolver()->capabilitiesForGuc($this->admin));
        $this->assertContains('enrolment.create', $this->resolver()->capabilitiesForProgramme($this->admin, $this->programmeX));

        $y = $this->makeProgramme(); // a different programme with no override → not held there
        $this->assertNotContains('enrolment.create', $this->resolver()->capabilitiesForProgramme($this->admin, $y));
    }

    public function test_school_specific_override_beats_all_schools(): void
    {
        $this->grants()->grant($this->platform, $this->school->id, 'teams.approve');

        // all-schools WITHHOLD + school-specific GRANT on X → HELD (specific wins)
        $this->grants()->setOverride($this->platform, $this->programmeX, null, 'teams.approve', 'withhold');
        $this->grants()->setOverride($this->platform, $this->programmeX, $this->school->id, 'teams.approve', 'grant');
        $this->assertContains('teams.approve', $this->resolver()->capabilitiesForProgramme($this->admin, $this->programmeX));

        // reverse on another programme: all-schools GRANT + school-specific WITHHOLD → NOT held (specific wins)
        $y = $this->makeProgramme();
        $this->grants()->setOverride($this->platform, $y, null, 'teams.approve', 'grant');
        $this->grants()->setOverride($this->platform, $y, $this->school->id, 'teams.approve', 'withhold');
        $this->assertNotContains('teams.approve', $this->resolver()->capabilitiesForProgramme($this->admin, $y));
    }

    public function test_a_never_capability_is_filtered_defense_in_depth(): void
    {
        // A hypothetical bad row: the AuthorityGrantService would REJECT consent.sign, so raw-insert under the
        // harness system context to bypass the service — the resolver's ∩ delegable filter must still drop it.
        DB::table('school_authority_grants')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'capability' => 'consent.sign',
            'granted_by' => $this->platform->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotContains('consent.sign', $this->resolver()->capabilitiesForGuc($this->admin),
            'a never-capability leaked past the resolver defense-in-depth filter');
    }

    public function test_caps_land_in_app_capabilities_via_scopecontext(): void
    {
        $this->grants()->grant($this->platform, $this->school->id, 'teams.approve');

        app(ScopeContext::class)->set($this->admin);
        $caps = DB::selectOne("SELECT current_setting('app.capabilities', true) AS v")->v;
        app(ScopeContext::class)->setSystem();

        $this->assertStringContainsString('teams.approve', $caps);
    }

    public function test_teacher_also_gains_delegated_caps(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        DB::table('teacher_links')->insert([
            'id' => (string) Str::uuid7(), 'teacher_id' => $teacher->id,
            'school_id' => $this->school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->grants()->grant($this->platform, $this->school->id, 'teams.approve');

        $this->assertContains('teams.approve', $this->resolver()->capabilitiesForGuc($teacher));
    }

    /** behaviour-sha guard: the academy_admin branch is unchanged — group caps only, no delegated contamination. */
    public function test_academy_admin_branch_unchanged(): void
    {
        $ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $ops->id,
            'capability' => 'operations', 'granted_by' => $ops->id, 'granted_at' => now(),
        ]);

        app(ScopeContext::class)->set($ops);
        $caps = DB::selectOne("SELECT current_setting('app.capabilities', true) AS v")->v;
        app(ScopeContext::class)->setSystem();

        $this->assertSame('operations', $caps); // exactly the group name — the branch did not change
    }
}
