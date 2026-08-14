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
 * A-4 (teams) — the additive delegated read arm on teams_read. Proves: a delegated cap opens a genuinely-new
 * cross-lobby / un-linked read; the arm HONORS withhold per-programme (denies P while the request-wide GUC
 * still lists the cap — the arm is not GUC-only); and RIDER-1 (a no-grant school_admin is unchanged; other
 * roles unaffected). Teams' lobbies belong to schoolT, so lobbySchoolAdmin never covers them for schoolS —
 * isolating the delegated arm as the sole grantor.
 */
class TeamsDelegatedReadTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolS;   // the delegated actor's school

    private School $schoolT;   // owns the team lobbies (cross-lobby for S)

    private School $schoolU;   // a no-grant school

    private User $adminS;

    private User $platform;

    private int $progP;

    private int $progQ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolS = School::query()->create(['name_en' => 'S', 'name_tc' => 'S', 'name_sc' => 'S']);
        $this->schoolT = School::query()->create(['name_en' => 'T', 'name_tc' => 'T', 'name_sc' => 'T']);
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
        return DB::table('programmes')->insertGetId(['code' => 'A4-'.Str::upper(Str::random(6)),
            'name_en' => 'P', 'name_tc' => '課', 'name_sc' => '课', 'jurisdiction' => 'HK', 'payer_party' => 'parent',
            'status' => 'draft', 'is_template' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function category(int $programmeId, ?int $schoolId): string
    {
        $id = (string) Str::uuid7();
        DB::table('team_categories')->insert(['id' => $id, 'programme_id' => $programmeId,
            'name_en' => 'C', 'name_tc' => 'C', 'name_sc' => 'C', 'school_id' => $schoolId,
            'assignment_rule' => 'open', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function team(int $programmeId, string $categoryId): string
    {
        $id = (string) Str::uuid7();
        DB::table('teams')->insert(['id' => $id, 'programme_id' => $programmeId, 'category_id' => $categoryId,
            'name' => 'Team', 'created_by' => $this->platform->id, 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function grants(): AuthorityGrantService
    {
        return app(AuthorityGrantService::class);
    }

    /** @return array{ids: list<string>, caps: string} teams visible under $u's RLS + the app.capabilities GUC seen. */
    private function seenAs(User $u): array
    {
        app(ScopeContext::class)->set($u);
        $ids = DB::table('teams')->pluck('id')->all();
        $caps = DB::selectOne("SELECT current_setting('app.capabilities', true) AS v")->v;
        app(ScopeContext::class)->setSystem();

        return ['ids' => $ids, 'caps' => $caps];
    }

    /** THE must-have test: withhold bites at RLS while the cap stays in the request-wide GUC. */
    public function test_withhold_denies_that_programme_while_the_cap_stays_in_the_guc(): void
    {
        $teamP = $this->team($this->progP, $this->category($this->progP, $this->schoolT->id)); // lobby = T (cross-lobby for S)
        $teamQ = $this->team($this->progQ, $this->category($this->progQ, $this->schoolT->id));

        $this->grants()->grant($this->platform, $this->schoolS->id, 'teams.approve');                 // school-wide baseline
        $this->grants()->setOverride($this->platform, $this->progP, $this->schoolS->id, 'teams.approve', 'withhold'); // withhold on P

        $seen = $this->seenAs($this->adminS);

        // teams.approve IS in the request-wide GUC (superset) — so this proves the arm is NOT GUC-only
        $this->assertStringContainsString('teams.approve', $seen['caps']);
        // sibling Q: held for the programme → the delegated arm GRANTS read
        $this->assertContains($teamQ, $seen['ids']);
        // P: withheld for the programme → DENIED, even though the GUC lists teams.approve
        $this->assertNotContains($teamP, $seen['ids'], 'withhold did not bite at RLS — the arm gated on the GUC alone');
    }

    /** A delegated cap opens a genuinely-new read path (cross-lobby) that no existing arm grants. */
    public function test_delegated_cap_opens_a_new_cross_lobby_read(): void
    {
        $team = $this->team($this->progP, $this->category($this->progP, $this->schoolT->id)); // lobby = T, not S

        // BEFORE any grant: schoolS admin cannot see it (lobbySchoolAdmin covers only S's own lobby)
        $this->assertNotContains($team, $this->seenAs($this->adminS)['ids']);

        // AFTER a delegated teams.view grant to schoolS: the new arm opens the read
        $this->grants()->grant($this->platform, $this->schoolS->id, 'teams.view');
        $this->assertContains($team, $this->seenAs($this->adminS)['ids'], 'delegated teams.view did not open the new read path');
    }

    /** An UN-LINKED teacher (no team_teacher_links) reads via the school's delegation. */
    public function test_unlinked_teacher_reads_via_delegation(): void
    {
        $team = $this->team($this->progP, $this->category($this->progP, $this->schoolT->id));
        $teacher = User::factory()->create(['role' => 'teacher']);
        DB::table('teacher_links')->insert(['id' => (string) Str::uuid7(), 'teacher_id' => $teacher->id,
            'school_id' => $this->schoolS->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotContains($team, $this->seenAs($teacher)['ids']); // no link, no member → invisible today
        $this->grants()->grant($this->platform, $this->schoolS->id, 'teams.approve');
        $this->assertContains($team, $this->seenAs($teacher)['ids']);    // school's delegation opens it
    }

    /** RIDER-1: a no-grant school_admin sees EXACTLY what it saw before (its own lobby only — new arm adds nothing). */
    public function test_rider1_no_grant_school_admin_unchanged(): void
    {
        // the delegated teams live in T's lobby on P/Q
        $teamP = $this->team($this->progP, $this->category($this->progP, $this->schoolT->id));
        // U's OWN-lobby team on P — U's admin sees this via lobbySchoolAdmin (unchanged)
        $ownTeam = $this->team($this->progP, $this->category($this->progP, $this->schoolU->id));

        $this->grants()->grant($this->platform, $this->schoolS->id, 'teams.approve'); // S is delegated; U is NOT

        $adminU = $this->schoolAdmin($this->schoolU->id);
        $seen = $this->seenAs($adminU)['ids'];

        $this->assertContains($ownTeam, $seen, 'lobbySchoolAdmin (own lobby) access changed');
        $this->assertNotContains($teamP, $seen, 'a no-grant school_admin gained delegated access it should not have');
    }

    /** RIDER-1: other roles are untouched — the arm is role-gated to school_admin/teacher. */
    public function test_rider1_member_and_foreign_student_unaffected(): void
    {
        $this->team($this->progP, $this->category($this->progP, $this->schoolT->id));
        $this->grants()->grant($this->platform, $this->schoolS->id, 'teams.approve');

        $member = User::factory()->create(['role' => 'member']);
        $this->assertSame([], $this->seenAs($member)['ids'], 'a member saw teams — the delegated arm is not role-gated');

        $student = User::factory()->create(['role' => 'student']); // not enrolled, not on a team
        $this->assertSame([], $this->seenAs($student)['ids'], 'an unrelated student saw teams');
    }
}
