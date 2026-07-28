<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentActivationService;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Reconciliation\Assertions\ActivationLivenessAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrolmentActivationTest extends TestCase
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

    /** @return array{0: Programme, 1: string} programme, lobby. $started=false uses future dates. */
    private function publishedProgramme(bool $started = true): array
    {
        [$closes, $deadline, $starts] = $started
            ? ['2026-06-10', '2026-06-20', '2026-06-30']   // in the past → started
            : ['2026-12-10', '2026-12-20', '2026-12-30'];  // in the future → not yet started
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'AC-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => $closes, 'starts_on' => $starts],
                'eligibility' => ['capacity' => 10],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId],
                'team_rules' => ['formation_deadline_on' => $deadline, 'min_team_size' => 2],
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
        app(ScopeContext::class)->set($this->ops);
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

    /** @return array{0:string,1:list<User>} teamId, members (all confirmed via 成團) */
    private function confirmedTeam(Programme $programme, string $lobby, int $size = 2): array
    {
        $creator = $this->pooledStudent($programme);
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $programme->id, 'category_id' => $lobby, 'name' => 'Team'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        $members = [$creator];
        for ($i = 1; $i < $size; $i++) {
            $m = $this->pooledStudent($programme);
            Sanctum::actingAs($m);
            $this->postJson("/api/teams/{$teamId}/join")->assertOk();
            $this->app['auth']->forgetGuards();
            $members[] = $m;
        }
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $this->app['auth']->forgetGuards();

        return [$teamId, $members];
    }

    private function statusOf(Programme $programme, User $student): string
    {
        return $this->sys(fn () => DB::table('enrolments')->where('programme_id', $programme->id)->where('student_id', $student->id)->value('status'));
    }

    public function test_activates_confirmed_enrolments_in_a_started_programme_as_system(): void
    {
        [$programme, $lobby] = $this->publishedProgramme(started: true);
        [, $members] = $this->confirmedTeam($programme, $lobby, 2);
        foreach ($members as $m) {
            $this->assertSame('confirmed', $this->statusOf($programme, $m));
        }

        $result = app(EnrolmentActivationService::class)->run();

        $this->assertSame(2, $result['activated']);
        foreach ($members as $m) {
            $this->assertSame('active', $this->statusOf($programme, $m));
        }
        // SYSTEM-actor audit on activation (OD-64)
        $this->sys(fn () => $this->assertDatabaseHas('audit_events', ['entity_type' => 'enrolment', 'action' => 'enrolment.active', 'actor_role' => 'system']));
    }

    public function test_not_yet_started_programme_stays_confirmed(): void
    {
        [$programme, $lobby] = $this->publishedProgramme(started: false);
        [, $members] = $this->confirmedTeam($programme, $lobby, 2);

        $result = app(EnrolmentActivationService::class)->run();

        $this->assertSame(0, $result['activated']);
        foreach ($members as $m) {
            $this->assertSame('confirmed', $this->statusOf($programme, $m)); // programme not started → not active
        }
    }

    public function test_late_joiner_confirmed_after_start_activates_on_the_next_run_not_stranded(): void
    {
        // programme already started; a team 成團s "now" (after start) — a late joiner
        [$programme, $lobby] = $this->publishedProgramme(started: true);
        [, $members] = $this->confirmedTeam($programme, $lobby, 2);
        // immediately after 成團, before the next job run: confirmed, NOT active
        foreach ($members as $m) {
            $this->assertSame('confirmed', $this->statusOf($programme, $m));
        }
        // the next scheduled run picks them up → active (max(start, confirmed_at) reduces to "started")
        app(EnrolmentActivationService::class)->run();
        foreach ($members as $m) {
            $this->assertSame('active', $this->statusOf($programme, $m), 'a late joiner activates on the next run, not stranded');
        }
    }

    public function test_tracker_gate_locked_before_active_allowed_after(): void
    {
        [$programme, $lobby] = $this->publishedProgramme(started: false); // future start
        [$teamId, ] = $this->confirmedTeam($programme, $lobby, 2);
        $teacher = User::factory()->create(['role' => 'teacher']);
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/teams/{$teamId}/teacher-link", ['teacher_id' => $teacher->id])->assertOk();
        $this->app['auth']->forgetGuards();

        // before the programme starts, an AUTHORISED approver is still locked out (422, FR012)
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->assertStatus(422);
        $this->app['auth']->forgetGuards();

        // the programme begins (starts_on moves into the past) → the gate is allowed
        $this->sys(fn () => DB::table('wizard_sections')->where('programme_id', $programme->id)->where('section_key', 'basics')
            ->update(['data' => json_encode(['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'])]));
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->assertOk()->assertJsonPath('approver_kind', 'teacher');
        $this->app['auth']->forgetGuards();
    }

    public function test_activation_liveness_assertion_reds_until_the_job_runs(): void
    {
        // a started programme with confirmed-but-unactivated members → RED
        [$programme, $lobby] = $this->publishedProgramme(started: true);
        $this->confirmedTeam($programme, $lobby, 2);
        $red = $this->sys(fn () => (new ActivationLivenessAssertion)->check());
        $this->assertFalse($red->passed, 'confirmed enrolments in a started programme, job not run → red');
        $this->assertStringContainsString('un-activated', $red->details);

        // run the job → GREEN
        app(EnrolmentActivationService::class)->run();
        $this->assertTrue($this->sys(fn () => (new ActivationLivenessAssertion)->check()->passed), 'running the job restores green');
    }

    public function test_activation_liveness_ignores_not_yet_started_programmes(): void
    {
        // confirmed members in a NOT-started programme are legitimately not active → assertion stays green
        [$programme, $lobby] = $this->publishedProgramme(started: false);
        $this->confirmedTeam($programme, $lobby, 2);
        $this->assertTrue($this->sys(fn () => (new ActivationLivenessAssertion)->check()->passed), 'not-yet-started confirmed enrolments are not a violation');
    }
}
