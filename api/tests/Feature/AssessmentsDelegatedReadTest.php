<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\AuthorityGrantService;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A-4 (assessments) — the two additive delegated read arms. READING (ii), EMBARGOED. The LOAD-BEARING test is
 * the embargo one: a delegated school sees a RELEASED result but NOT a graded-but-unreleased result. Also:
 * withhold bites on released results; the schedule arm opens a new read; RIDER-1 (the delegated results arm is
 * released-only — never exceeds a guardian; ops/audit see-all unchanged; a no-grant school_admin sees nothing).
 */
class AssessmentsDelegatedReadTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolS;

    private School $schoolU;

    private User $adminS;

    private User $platform;

    private int $progP;

    private int $progQ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolS = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        $this->schoolU = School::query()->create(['name_en' => 'U', 'name_tc' => 'U', 'name_sc' => 'U']);
        $this->adminS = $this->schoolAdmin($this->schoolS->id);
        $this->platform = User::factory()->create(['role' => 'academy_admin']);
        $this->progP = $this->makeProgramme();
        $this->progQ = $this->makeProgramme();
    }

    private function schoolAdmin(int $schoolId): User
    {
        $admin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $admin->id,
            'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $admin;
    }

    private function makeProgramme(): int
    {
        return DB::table('programmes')->insertGetId(['code' => 'A4A-'.Str::upper(Str::random(6)),
            'name_en' => 'P', 'name_tc' => '課', 'name_sc' => '课', 'jurisdiction' => 'HK', 'payer_party' => 'parent',
            'status' => 'draft', 'is_template' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeAssessment(int $programmeId, string $status): string
    {
        $id = (string) Str::uuid7();
        DB::table('assessments')->insert(['id' => $id, 'programme_id' => $programmeId, 'title' => 'A',
            'status' => $status, 'created_by' => $this->platform->id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** A graded result on $assessmentId — a fresh student + enrolment per call (enrolments_one_live forbids two
     *  live enrolments for one student in a programme; the delegated arm isn't student-specific). Returns the id. */
    private function makeResult(string $assessmentId, int $programmeId): string
    {
        $student = User::factory()->create(['role' => 'student']);
        $enrolmentId = (string) Str::uuid7();
        DB::table('enrolments')->insert(['id' => $enrolmentId, 'programme_id' => $programmeId,
            'student_id' => $student->id, 'acting_guardian_id' => $this->platform->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid7();
        DB::table('assessment_results')->insert(['id' => $id, 'assessment_id' => $assessmentId,
            'enrolment_id' => $enrolmentId, 'student_id' => $student->id, 'score' => 80,
            'graded_by' => $this->platform->id, 'graded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function grants(): AuthorityGrantService
    {
        return app(AuthorityGrantService::class);
    }

    private function opsAdmin(): User
    {
        $ops = User::factory()->create(['role' => 'academy_admin']);
        DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $ops->id,
            'capability' => 'operations', 'granted_by' => $ops->id, 'granted_at' => now()]);

        return $ops;
    }

    /** @return array{results: list<string>, assessments: list<string>, caps: string} */
    private function seenAs(User $u): array
    {
        app(ScopeContext::class)->set($u);
        $results = DB::table('assessment_results')->pluck('id')->all();
        $assessments = DB::table('assessments')->pluck('id')->all();
        $caps = DB::selectOne("SELECT current_setting('app.capabilities', true) AS v")->v;
        app(ScopeContext::class)->setSystem();

        return ['results' => $results, 'assessments' => $assessments, 'caps' => $caps];
    }

    /** THE LOAD-BEARING test: the delegated arm honors the embargo — released seen, graded-but-unreleased NOT. */
    public function test_embargo_delegated_sees_released_result_not_unreleased(): void
    {
        $released = $this->makeResult($this->makeAssessment($this->progP, 'released'), $this->progP);
        $unreleased = $this->makeResult($this->makeAssessment($this->progP, 'graded'), $this->progP); // graded, NOT released

        $this->grants()->grant($this->platform, $this->schoolS->id, 'student_records.view');

        $seen = $this->seenAs($this->adminS)['results'];
        $this->assertContains($released, $seen, 'delegated school did not see the released result');
        $this->assertNotContains($unreleased, $seen, 'EMBARGO BREACH — delegated school saw a graded-but-unreleased grade');
    }

    /** Withhold bites on released results, while student_records.view stays in the request-wide GUC. */
    public function test_withhold_denies_that_programmes_released_results_while_cap_in_guc(): void
    {
        $rP = $this->makeResult($this->makeAssessment($this->progP, 'released'), $this->progP);
        $rQ = $this->makeResult($this->makeAssessment($this->progQ, 'released'), $this->progQ);

        $this->grants()->grant($this->platform, $this->schoolS->id, 'student_records.view');
        $this->grants()->setOverride($this->platform, $this->progP, $this->schoolS->id, 'student_records.view', 'withhold');

        $seen = $this->seenAs($this->adminS);
        $this->assertStringContainsString('student_records.view', $seen['caps']); // still in the GUC
        $this->assertContains($rQ, $seen['results']);                             // Q held + released → seen
        $this->assertNotContains($rP, $seen['results'], 'withhold did not bite on the released result');
    }

    /** The schedule arm (assessments_read, no embargo) opens a genuinely-new read. */
    public function test_schedule_arm_opens_new_read(): void
    {
        $a = $this->makeAssessment($this->progP, 'open');

        $this->assertNotContains($a, $this->seenAs($this->adminS)['assessments']); // no school_admin arm today
        $this->grants()->grant($this->platform, $this->schoolS->id, 'enrolment.view');
        $this->assertContains($a, $this->seenAs($this->adminS)['assessments']);
    }

    /** RIDER-1: the delegated results arm is RELEASED-ONLY — never exceeds a guardian's release-gated visibility. */
    public function test_rider1_delegated_never_exceeds_guardian_released_only(): void
    {
        // every unreleased state the delegated arm might see, it must NOT — identical to a guardian's release gate
        foreach (['draft', 'published', 'open', 'closed', 'graded'] as $unreleasedStatus) {
            $r = $this->makeResult($this->makeAssessment($this->progP, $unreleasedStatus), $this->progP);
            $this->grants()->grant($this->platform, $this->schoolS->id, 'student_records.view');
            $this->assertNotContains($r, $this->seenAs($this->adminS)['results'],
                "delegated arm exceeded guardian visibility on status '{$unreleasedStatus}'");
        }
    }

    /** RIDER-1: ops/audit see-all is unchanged; a no-grant school_admin sees nothing. */
    public function test_rider1_opsaudit_see_all_and_no_grant_admin_sees_nothing(): void
    {
        $released = $this->makeResult($this->makeAssessment($this->progP, 'released'), $this->progP);
        $unreleased = $this->makeResult($this->makeAssessment($this->progP, 'graded'), $this->progP);
        $this->grants()->grant($this->platform, $this->schoolS->id, 'student_records.view'); // S delegated, U not

        // ops sees BOTH states (embargo does not apply to internal academy delivery) — unchanged
        $opsSeen = $this->seenAs($this->opsAdmin())['results'];
        $this->assertContains($released, $opsSeen);
        $this->assertContains($unreleased, $opsSeen);

        // a no-grant school_admin sees NOTHING (school_admin absent from both policies without a grant)
        $adminU = $this->schoolAdmin($this->schoolU->id);
        $seenU = $this->seenAs($adminU);
        $this->assertSame([], $seenU['results']);
        $this->assertSame([], $seenU['assessments']);
    }
}
