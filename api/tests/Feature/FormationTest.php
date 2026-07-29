<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class FormationTest extends TestCase
{
    use RefreshDatabase;

    use \Illuminate\Foundation\Testing\WithFaker;

    private User $ops;

    private Programme $programme;

    private string $templateId;

    private string $openLobby;

    private string $stPaulsLobby;

    private School $stPauls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->stPauls = School::query()->create(['name_en' => "St Paul's", 'name_tc' => '聖保羅', 'name_sc' => '圣保罗']);
        Sanctum::actingAs($this->ops);
        $this->templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$this->templateId}/versions/{$v}/publish")->assertOk();
        }
        $this->programme = Programme::query()->create(['code' => 'TEAM-'.Str::upper(Str::random(4)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) { 'fees' => ['has_fee_items' => true], 'consent' => ['template_ref' => $this->templateId], default => ['x' => 1] };
            $this->putJson("/api/admin/programmes/{$this->programme->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$this->programme->id}/publish")->assertOk();
        // two lobbies: an open default + a St Paul's-bound lobby
        $this->openLobby = $this->postJson("/api/admin/programmes/{$this->programme->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開放', 'name_sc' => '开放', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $this->stPaulsLobby = $this->postJson("/api/admin/programmes/{$this->programme->id}/team-categories", ['name_en' => "St Paul's", 'name_tc' => '聖', 'name_sc' => '圣', 'assignment_rule' => 'auto_by_school', 'school_id' => $this->stPauls->id])->json('id');
        $this->app['auth']->forgetGuards();
    }

    /** A consented student in the pool (in_pool), optionally school-linked. */
    private function pooledStudent(?School $school = null): User
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        if ($school) {
            DB::table('school_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'school_id' => $school->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $id = $this->postJson('/api/my/enrolments', ['programme_id' => $this->programme->id, 'student_id' => $student->id])->json('id');
        // sign consent → in_pool
        $req = DB::table('consent_requests')->where('student_id', $student->id)->where('signer_id', $guardian->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->getJson("/api/consent-requests/{$req->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'G'])->assertStatus(201);
        app(EnrolmentService::class)->evaluateConsentGate($this->programme->id, $student->id, $guardian);
        $this->assertSame('in_pool', DB::table('enrolments')->where('id', $id)->value('status'));
        $this->app['auth']->forgetGuards();

        return $student;
    }

    public function test_create_team_in_open_lobby_teams_the_creator(): void
    {
        $s = $this->pooledStudent();
        Sanctum::actingAs($s);
        $team = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->openLobby, 'name' => 'Rockets'])->assertStatus(201)->json();
        $this->assertSame('forming', $team['status']);
        // creator is a member; their enrolment moved in_pool → teamed
        $this->assertSame('teamed', DB::table('enrolments')->where('student_id', $s->id)->value('status'));
        $this->assertDatabaseHas('team_members', ['team_id' => $team['id'], 'student_id' => $s->id, 'status' => 'active']);
        $this->assertDatabaseHas('audit_events', ['entity_type' => 'team', 'action' => 'team.formed', 'actor_id' => $s->id]);
    }

    public function test_join_teams_the_member(): void
    {
        $creator = $this->pooledStudent();
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->openLobby, 'name' => 'Rockets'])->json('id');
        $this->app['auth']->forgetGuards();
        $joiner = $this->pooledStudent();
        Sanctum::actingAs($joiner);
        $this->postJson("/api/teams/{$teamId}/join")->assertOk();
        $this->assertSame('teamed', DB::table('enrolments')->where('student_id', $joiner->id)->value('status'));
        $this->assertSame(2, DB::table('team_members')->where('team_id', $teamId)->count());
    }

    public function test_school_bound_lobby_refuses_an_unlinked_student(): void
    {
        $unlinked = $this->pooledStudent(); // no school link
        Sanctum::actingAs($unlinked);
        // S02B partner-roster scoping: the unlinked student can't even SEE the
        // bound lobby (team_categories RLS), so it's refused generically — no
        // "St Paul's is a partner" leak. This SUPERSEDES TEAM-CATEGORIES §5's
        // inline "not linked to X" nicety (which would disclose the roster).
        $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->stPaulsLobby, 'name' => 'Xteam'])
            ->assertStatus(422)->assertJsonPath('errors.lobby.0', fn ($m) => str_contains($m, 'does not belong to this programme'));
        $this->assertSame(0, DB::table('teams')->count());
        // a St Paul's student CAN
        $this->app['auth']->forgetGuards();
        $linked = $this->pooledStudent($this->stPauls);
        Sanctum::actingAs($linked);
        $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->stPaulsLobby, 'name' => 'Paulines'])->assertStatus(201);
    }

    public function test_cross_lobby_join_is_impossible_via_school_binding(): void
    {
        // a St Paul's team exists; an unlinked student cannot join it (§8)
        $paul = $this->pooledStudent($this->stPauls);
        Sanctum::actingAs($paul);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->stPaulsLobby, 'name' => 'Paulines'])->json('id');
        $this->app['auth']->forgetGuards();
        $outsider = $this->pooledStudent();
        Sanctum::actingAs($outsider);
        // the outsider can't even SEE the bound-lobby team (RLS lobby wall) → 404
        $this->postJson("/api/teams/{$teamId}/join")->assertStatus(404);
        $this->assertSame(1, DB::table('team_members')->where('team_id', $teamId)->count());
    }

    public function test_one_team_per_student_and_pool_precondition(): void
    {
        $s = $this->pooledStudent();
        Sanctum::actingAs($s);
        $t1 = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->openLobby, 'name' => 'Ateam'])->json('id');
        // already teamed → no longer in_pool → cannot form/join again
        $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->openLobby, 'name' => 'Bteam'])
            ->assertStatus(422)->assertJsonPath('errors.enrolment.0', fn ($m) => str_contains($m, 'consented, unteamed'));
        $this->assertSame(1, DB::table('teams')->count());

        // a pending_consent student (not in pool) cannot form
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/my/enrolments', ['programme_id' => $this->programme->id, 'student_id' => $student->id]); // pending_consent
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($student);
        $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->openLobby, 'name' => 'Cteam'])->assertStatus(422);
    }

    public function test_lobby_resolution_marks_eligibility(): void
    {
        $paul = $this->pooledStudent($this->stPauls);
        Sanctum::actingAs($paul);
        $lobbies = collect($this->getJson("/api/programmes/{$this->programme->id}/lobbies")->assertOk()->json('data'));
        $this->assertTrue($lobbies->firstWhere('id', $this->stPaulsLobby)['eligible'], 'linked → St Paul\'s lobby eligible');
        $this->assertTrue($lobbies->firstWhere('id', $this->openLobby)['eligible'], 'open → eligible');
        $this->app['auth']->forgetGuards();
        $plain = $this->pooledStudent();
        Sanctum::actingAs($plain);
        $lobbies = collect($this->getJson("/api/programmes/{$this->programme->id}/lobbies")->json('data'));
        $this->assertNull($lobbies->firstWhere('id', $this->stPaulsLobby), 'unlinked student never sees the bound lobby (S02B roster scoping)');
        $this->assertNotNull($lobbies->firstWhere('id', $this->openLobby), 'open lobby is visible');
    }

    public function test_five_branch_isolation_on_teams(): void
    {
        $creator = $this->pooledStudent($this->stPauls);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->stPaulsLobby, 'name' => 'Paulines'])->json('id');
        $this->app['auth']->forgetGuards();

        // [1] member student sees own team
        Sanctum::actingAs($creator);
        $this->assertCount(1, $this->getJson('/api/teams')->json('data'));
        // [2] the member's guardian sees it
        $guardianId = DB::table('guardian_links')->where('student_id', $creator->id)->value('guardian_id');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::find($guardianId));
        $this->assertCount(1, $this->getJson('/api/teams')->json('data'));
        // [3] a NON-member student in the same programme sees zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->pooledStudent());
        $this->assertCount(0, $this->getJson('/api/teams')->json('data'));
        // [4] St Paul's school admin sees teams in their lobby; other-school admin zero
        $spAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $spAdmin->id, 'school_id' => $this->stPauls->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($spAdmin);
        $this->assertCount(1, $this->getJson('/api/teams')->json('data'));
        $otherSchool = School::query()->create(['name_en' => 'Other', 'name_tc' => '他', 'name_sc' => '他']);
        $otherAdmin = User::factory()->create(['role' => 'school_admin']);
        DB::table('school_admin_links')->insert(['id' => (string) Str::uuid7(), 'school_admin_id' => $otherAdmin->id, 'school_id' => $otherSchool->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($otherAdmin);
        $this->assertCount(0, $this->getJson('/api/teams')->json('data'));
        // [5] Member role: zero
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->assertCount(0, $this->getJson('/api/teams')->json('data'));
    }
}
