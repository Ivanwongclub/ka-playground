<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Teams\LearnGateService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class LearnGateTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
    }

    private function sys(callable $fn): mixed
    {
        $scope = app(ScopeContext::class);
        $scope->setSystem();
        try {
            return $fn();
        } finally {
            $scope->reset();
        }
    }

    /** @return array{0: Programme, 1: string} programme, open lobby (started programme) */
    private function publishedProgramme(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'LG-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'], // started
                'eligibility' => ['capacity' => 10],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'team_rules' => ['formation_deadline_on' => '2026-06-20', 'min_team_size' => 2],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$programme->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$programme->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 250000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$programme->id}/publish")->assertOk();
        $lobby = $this->postJson("/api/admin/programmes/{$programme->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $this->app['auth']->forgetGuards();

        return [$programme, $lobby];
    }

    private function pooledStudent(Programme $programme): User
    {
        app(ScopeContext::class)->setSystem();
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/my/enrolments', ['programme_id' => $programme->id, 'student_id' => $student->id]);
        $req = DB::table('consent_requests')->where('student_id', $student->id)->where('signer_id', $guardian->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->getJson("/api/consent-requests/{$req->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'G'])->assertStatus(201);
        app(EnrolmentService::class)->evaluateConsentGate($programme->id, $student->id, $guardian);
        $this->app['auth']->forgetGuards();

        return $student;
    }

    /** @return array{0:string,1:list<User>,2:User} teamId, members, linked-teacher */
    private function confirmedTeamWithTeacher(Programme $programme, string $lobby): array
    {
        $creator = $this->pooledStudent($programme);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        $m2 = $this->pooledStudent($programme);
        Sanctum::actingAs($m2);
        $this->postJson("/api/teams/{$teamId}/join")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->postJson("/api/admin/teams/{$teamId}/teacher-link", ['teacher_id' => $teacher->id])->assertOk();
        $this->app['auth']->forgetGuards();

        return [$teamId, [$creator, $m2], $teacher];
    }

    /** Create a session, publish, have each member book, run it, and mark attendance per $marks[student_id]. */
    private function runSessionWithAttendance(Programme $programme, array $members, array $marks): void
    {
        Sanctum::actingAs($this->ops);
        $sid = $this->postJson("/api/admin/programmes/{$programme->id}/sessions", [
            'title' => 'Learn', 'starts_at' => '2026-07-01 10:00:00', 'ends_at' => '2026-07-01 11:00:00', 'capacity' => 10,
        ])->json('id');
        $this->postJson("/api/admin/sessions/{$sid}/transition", ['to' => 'published'])->assertOk();
        $this->app['auth']->forgetGuards();
        foreach ($members as $m) {
            Sanctum::actingAs($m);
            $this->postJson("/api/my/sessions/{$sid}/book")->assertOk();
            $this->app['auth']->forgetGuards();
        }
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/sessions/{$sid}/transition", ['to' => 'in_progress'])->assertOk();
        foreach ($marks as $studentId => $status) {
            $this->postJson("/api/admin/sessions/{$sid}/attendance", ['student_id' => $studentId, 'status' => $status])->assertOk();
        }
        $this->app['auth']->forgetGuards();
    }

    private function approveLearn(string $teamId, User $teacher): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($teacher);
        $r = $this->postJson("/api/teams/{$teamId}/gates/Learn/approve");
        $this->app['auth']->forgetGuards();

        return $r;
    }

    public function test_learn_gate_refused_when_not_yet_assessable_zero_of_zero(): void
    {
        [$programme, $lobby] = $this->publishedProgramme();
        [$teamId, , $teacher] = $this->confirmedTeamWithTeacher($programme, $lobby);
        // no sessions / no attendance → 0/0 → not assessable → refused (never silently passed)
        $this->approveLearn($teamId, $teacher)->assertStatus(422);
        $this->sys(fn () => $this->assertSame(0, DB::table('stage_gates')->where('team_id', $teamId)->where('stage', 'Learn')->count()));
    }

    public function test_learn_gate_refused_below_team_threshold(): void
    {
        [$programme, $lobby] = $this->publishedProgramme();
        [$teamId, $members, $teacher] = $this->confirmedTeamWithTeacher($programme, $lobby);
        // one member attends (100% qualifies), the other no-shows (0% does not) → 1/2 = 50% < 60% → not eligible
        $this->runSessionWithAttendance($programme, $members, [$members[0]->id => 'attended', $members[1]->id => 'no_show']);
        $this->approveLearn($teamId, $teacher)->assertStatus(422);
    }

    public function test_learn_eligible_allows_the_teachers_approval_option_b(): void
    {
        [$programme, $lobby] = $this->publishedProgramme();
        [$teamId, $members, $teacher] = $this->confirmedTeamWithTeacher($programme, $lobby);
        // both attend → both qualify (100%) → team 100% ≥ 60% → eligible; the teacher then approves (OD-61)
        $this->runSessionWithAttendance($programme, $members, [$members[0]->id => 'attended', $members[1]->id => 'attended']);

        $r = $this->approveLearn($teamId, $teacher)->assertOk()->json();
        $this->assertSame('teacher', $r['approver_kind']); // eligibility is a PRECONDITION, not an auto-pass
        $this->sys(fn () => $this->assertDatabaseHas('stage_gates', ['team_id' => $teamId, 'stage' => 'Learn', 'approver_kind' => 'teacher']));
    }

    public function test_eligibility_computation_per_member_and_team_rollup(): void
    {
        [$programme, $lobby] = $this->publishedProgramme();
        [$teamId, $members, ] = $this->confirmedTeamWithTeacher($programme, $lobby);
        $this->runSessionWithAttendance($programme, $members, [$members[0]->id => 'attended', $members[1]->id => 'no_show']);

        $e = $this->sys(function () use ($teamId) {
            $team = DB::table('teams')->where('id', $teamId)->first();
            return app(LearnGateService::class)->eligibility($team);
        });
        $this->assertTrue($e['assessable']);
        $this->assertSame(2, $e['active_members']);
        $this->assertSame(1, $e['qualifying']);      // only the attender qualifies
        $this->assertFalse($e['eligible']);           // 50% < 60%
        $this->assertSame(70, $e['attendance_threshold_pct']);
        $this->assertSame(60, $e['team_gate_pass_pct']);
    }

    public function test_preflight_warns_when_learn_threshold_but_no_sessions(): void
    {
        [$programme, ] = $this->publishedProgramme();
        Sanctum::actingAs($this->ops);
        // certification_rules defaults team_gate_pass_pct=60 on save; publish/pre-flight then warns (no sessions yet)
        $this->putJson("/api/admin/programmes/{$programme->id}/certification-rules", ['attendance_threshold_pct' => 70, 'team_gate_pass_pct' => 60])->assertOk();
        $findings = $this->postJson("/api/admin/programmes/{$programme->id}/pre-flight")->assertOk()->json('findings');
        $this->app['auth']->forgetGuards();

        $codes = array_column($findings, 'code');
        $this->assertContains('learn.no_sessions', $codes);
    }
}
