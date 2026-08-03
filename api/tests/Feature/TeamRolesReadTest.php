<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-UX3-3a STEP 3 — Backend delta B3: GET /teams/{team}/roles (roles & tenure ledger).
 * Proves the EXPLICIT authority shape (member-readable, resolved WITHIN RLS, no elevation — a DIFFERENT
 * five-branch than B0), the one-active-holder invariant reflected in the read, and count-preserving
 * double-gated holder names.
 */
class TeamRolesReadTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private User $lobbyAdmin;   // school-admin of the team's lobby (schoolA)

    private User $otherAdmin;   // school-admin of a DIFFERENT school (unaffiliated)

    private User $holder;       // student member holding the role

    private User $guardian;     // the holder's active guardian

    private User $second;       // a second member (rotation target)

    private int $programmeId;

    private string $teamId;

    private string $roleId;

    private string $enrolHolder;

    private string $enrolSecond;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops = $this->admin(['operations']);
        [$sA, $this->lobbyAdmin] = $this->schoolWithAdmin();
        [, $this->otherAdmin] = $this->schoolWithAdmin();

        $this->holder = User::factory()->create(['role' => 'student', 'name' => 'Cast Holder']);
        $this->guardian = User::factory()->create(['role' => 'guardian', 'name' => 'Guardian Gee']);
        $this->second = User::factory()->create(['role' => 'student', 'name' => 'Rota Second']);

        [$this->programmeId, $this->roleId, $this->teamId, $this->enrolHolder, $this->enrolSecond] = $this->sys(function () use ($sA) {
            $programmeId = DB::table('programmes')->insertGetId(['code' => 'RL'.Str::random(4), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK', 'payer_party' => 'parent', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
            $roleId = (string) Str::uuid7();
            DB::table('role_library')->insert(['id' => $roleId, 'programme_id' => $programmeId, 'name_en' => 'Captain', 'name_tc' => '隊長', 'name_sc' => '队长', 'min_holders' => 1, 'max_holders' => 1, 'mandatory' => true, 'created_at' => now(), 'updated_at' => now()]);
            $lobby = (string) Str::uuid7();
            DB::table('team_categories')->insert(['id' => $lobby, 'programme_id' => $programmeId, 'name_en' => 'Lobby', 'name_tc' => 'Lobby', 'name_sc' => 'Lobby', 'assignment_rule' => 'open', 'school_id' => $sA->id, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $teamId = (string) Str::uuid7();
            DB::table('teams')->insert(['id' => $teamId, 'programme_id' => $programmeId, 'category_id' => $lobby, 'name' => 'Alpha', 'status' => 'confirmed', 'created_by' => $this->holder->id, 'created_at' => now(), 'updated_at' => now()]);

            $enrols = [];
            foreach ([[$this->holder, $this->guardian], [$this->second, $this->guardian]] as [$stu, $g]) {
                $e = (string) Str::uuid7();
                DB::table('enrolments')->insert(['id' => $e, 'programme_id' => $programmeId, 'student_id' => $stu->id, 'acting_guardian_id' => $g->id, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'team_id' => $teamId, 'enrolment_id' => $e, 'category_id' => $lobby, 'student_id' => $stu->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                $enrols[] = $e;
            }
            // the holder's active guardian link (drives the guardian read branch)
            DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $this->holder->id, 'guardian_id' => $this->guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
            // an ACTIVE tenure for the holder on Captain
            DB::table('tenures')->insert(['id' => (string) Str::uuid7(), 'team_id' => $teamId, 'role_id' => $roleId, 'category_id' => $lobby, 'enrolment_id' => $enrols[0], 'student_id' => $this->holder->id, 'state' => 'active', 'started_at' => now(), 'assigned_by' => $this->ops->id, 'created_at' => now(), 'updated_at' => now()]);

            return [$programmeId, $roleId, $teamId, $enrols[0], $enrols[1]];
        });
    }

    // ── (a) authority: MEMBER-READABLE, a DIFFERENT five-branch than B0 ──────────────────────────────
    public function test_roles_read_is_member_readable_within_rls(): void
    {
        // ops → 200, full roster with the holder named
        $this->act($this->ops);
        $body = $this->getJson("/api/teams/{$this->teamId}/roles")->assertOk()->json();
        $captain = collect($body['roles'])->firstWhere('role_id', $this->roleId);
        $this->assertSame($this->holder->id, $captain['current']['student_id']);
        $this->assertSame('Cast Holder', $captain['current']['student_name']);

        // lobby school-admin of the team's lobby → 200
        $this->act($this->lobbyAdmin);
        $this->getJson("/api/teams/{$this->teamId}/roles")->assertOk();

        // the HOLDER (a student member) → 200 and sees their OWN tenure — the branch where B0 returns 403
        $this->act($this->holder);
        $mine = $this->getJson("/api/teams/{$this->teamId}/roles")->assertOk()->json();
        $this->assertSame($this->holder->id, collect($mine['roles'])->firstWhere('role_id', $this->roleId)['current']['student_id']);

        // the holder's guardian → 200 (sees the child's tenure)
        $this->act($this->guardian);
        $this->getJson("/api/teams/{$this->teamId}/roles")->assertOk();

        // an unaffiliated school-admin → 404 (RLS-shaped absence, not a 403 existence leak)
        $this->act($this->otherAdmin);
        $this->getJson("/api/teams/{$this->teamId}/roles")->assertNotFound();
    }

    // ── (b) one-active-holder invariant reflected across a rotation ──────────────────────────────────
    public function test_read_reflects_one_active_holder_after_a_rotation(): void
    {
        // rotate Captain from the holder to the second member via the real write path
        $this->act($this->ops);
        $this->postJson("/api/teams/{$this->teamId}/roles", ['enrolment_id' => $this->enrolSecond, 'role_id' => $this->roleId])->assertOk();

        $body = $this->getJson("/api/teams/{$this->teamId}/roles")->assertOk()->json();
        $captain = collect($body['roles'])->firstWhere('role_id', $this->roleId);

        // exactly ONE current (the new holder); the prior holder is an ENDED past tenure
        $this->assertNotNull($captain['current']);
        $this->assertSame($this->second->id, $captain['current']['student_id']);
        $this->assertCount(1, $captain['past']);
        $this->assertSame($this->holder->id, $captain['past'][0]['student_id']);
        $this->assertNotNull($captain['past'][0]['ended_at'], 'a past tenure always carries an ended_at');
        $this->assertArrayNotHasKey('ended_at', $captain['current']); // current is the OPEN tenure — no end

        // and the DB invariant holds: never two active for the (team, role)
        $active = $this->sys(fn () => DB::table('tenures')->where('team_id', $this->teamId)->where('role_id', $this->roleId)->where('state', 'active')->count());
        $this->assertSame(1, $active);
    }

    // ── (c) count-preserving, double-gated holder names ─────────────────────────────────────────────
    public function test_holder_name_is_double_gated_and_row_preserved(): void
    {
        // the lobby admin sees the team's tenures (schoolAdminOf via the lobby), but the holder is not a
        // student of their school → users_read hides the name. The tenure row must survive with a NULL
        // name — never the raw id.
        $this->act($this->lobbyAdmin);
        $captain = collect($this->getJson("/api/teams/{$this->teamId}/roles")->assertOk()->json('roles'))
            ->firstWhere('role_id', $this->roleId);
        $this->assertNotNull($captain['current'], 'the tenure row is preserved even when the name is hidden');
        $this->assertNull($captain['current']['student_name'], 'a hidden holder resolves to NULL, never the raw id');
        $this->assertSame($this->holder->id, $captain['current']['student_id']); // the id is still the roster key
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
