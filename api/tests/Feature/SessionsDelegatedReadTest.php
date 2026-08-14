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
 * A-4 (sessions) — the additive delegated read arm on ps_read. Proves: a delegated enrolment.view opens a
 * genuinely-new read (a school_admin is absent from ps_read entirely today; a teacher reads only as the
 * assigned mentor); the arm HONORS withhold per-programme (denies P while the request-wide GUC still lists the
 * cap — not GUC-only); and RIDER-1 (a no-grant school_admin still reads ZERO sessions; assigned mentor,
 * member, foreign student unaffected). Sessions carry no school column, so the school comes only from the actor.
 */
class SessionsDelegatedReadTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolS;   // the delegated actor's school

    private School $schoolU;   // a no-grant school

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
        return DB::table('programmes')->insertGetId(['code' => 'A4S-'.Str::upper(Str::random(6)),
            'name_en' => 'P', 'name_tc' => '課', 'name_sc' => '课', 'jurisdiction' => 'HK', 'payer_party' => 'parent',
            'status' => 'draft', 'is_template' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeSession(int $programmeId, ?int $mentorId = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('programme_sessions')->insert(['id' => $id, 'programme_id' => $programmeId, 'title' => 'Session',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'capacity' => 20,
            'mentor_id' => $mentorId, 'status' => 'published', 'created_by' => $this->platform->id,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function grants(): AuthorityGrantService
    {
        return app(AuthorityGrantService::class);
    }

    /** @return array{ids: list<string>, caps: string} sessions visible under $u's RLS + the app.capabilities GUC. */
    private function seenAs(User $u): array
    {
        app(ScopeContext::class)->set($u);
        $ids = DB::table('programme_sessions')->pluck('id')->all();
        $caps = DB::selectOne("SELECT current_setting('app.capabilities', true) AS v")->v;
        app(ScopeContext::class)->setSystem();

        return ['ids' => $ids, 'caps' => $caps];
    }

    /** THE must-have test: withhold bites at RLS while enrolment.view stays in the request-wide GUC. */
    public function test_withhold_denies_that_programme_while_the_cap_stays_in_the_guc(): void
    {
        $sP = $this->makeSession($this->progP);
        $sQ = $this->makeSession($this->progQ);

        $this->grants()->grant($this->platform, $this->schoolS->id, 'enrolment.view');                 // school-wide baseline
        $this->grants()->setOverride($this->platform, $this->progP, $this->schoolS->id, 'enrolment.view', 'withhold'); // withhold on P

        $seen = $this->seenAs($this->adminS);

        $this->assertStringContainsString('enrolment.view', $seen['caps']); // still in the request-wide GUC
        $this->assertContains($sQ, $seen['ids']);                            // sibling Q held → granted
        $this->assertNotContains($sP, $seen['ids'], 'withhold did not bite at RLS — the arm gated on the GUC alone');
    }

    /** A delegated cap opens a genuinely-new read — a school_admin is absent from ps_read entirely today. */
    public function test_delegated_cap_opens_a_new_school_admin_read(): void
    {
        $s = $this->makeSession($this->progP);

        $this->assertNotContains($s, $this->seenAs($this->adminS)['ids']); // no school_admin arm exists today
        $this->grants()->grant($this->platform, $this->schoolS->id, 'enrolment.view');
        $this->assertContains($s, $this->seenAs($this->adminS)['ids'], 'delegated enrolment.view did not open the new read');
    }

    /** An un-linked, non-mentor teacher reads via the school's delegation. */
    public function test_unlinked_teacher_reads_via_delegation(): void
    {
        $s = $this->makeSession($this->progP); // mentor_id null → teacher is NOT the assigned mentor
        $teacher = User::factory()->create(['role' => 'teacher']);
        DB::table('teacher_links')->insert(['id' => (string) Str::uuid7(), 'teacher_id' => $teacher->id,
            'school_id' => $this->schoolS->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotContains($s, $this->seenAs($teacher)['ids']);
        $this->grants()->grant($this->platform, $this->schoolS->id, 'enrolment.view');
        $this->assertContains($s, $this->seenAs($teacher)['ids']);
    }

    /** RIDER-1 (strongest here): a no-grant school_admin still reads ZERO sessions — unchanged, since school_admin
     *  is absent from ps_read entirely and the new arm adds nothing without a delegated grant. */
    public function test_rider1_no_grant_school_admin_reads_no_sessions(): void
    {
        $this->makeSession($this->progP);
        $this->makeSession($this->progQ);
        $this->grants()->grant($this->platform, $this->schoolS->id, 'enrolment.view'); // S delegated, U is NOT

        $adminU = $this->schoolAdmin($this->schoolU->id);
        $this->assertSame([], $this->seenAs($adminU)['ids'], 'a no-grant school_admin gained session read it should not have');
    }

    /** RIDER-1: the existing arms are untouched — assigned mentor still reads; member/foreign student see nothing. */
    public function test_rider1_assigned_mentor_and_other_roles_unchanged(): void
    {
        $this->grants()->grant($this->platform, $this->schoolS->id, 'enrolment.view');

        $mentor = User::factory()->create(['role' => 'teacher']);
        $s = $this->makeSession($this->progP, $mentor->id); // mentor_id = this teacher
        $this->assertContains($s, $this->seenAs($mentor)['ids'], 'the assigned-mentor arm changed');

        $member = User::factory()->create(['role' => 'member']);
        $this->assertSame([], $this->seenAs($member)['ids'], 'a member saw sessions');

        $student = User::factory()->create(['role' => 'student']); // not enrolled → not on any session roster
        $this->assertSame([], $this->seenAs($student)['ids'], 'an unrelated student saw sessions');
    }
}
