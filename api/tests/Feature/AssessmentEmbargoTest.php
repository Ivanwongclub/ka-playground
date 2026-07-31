<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

class AssessmentEmbargoTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private Programme $programme;

    private string $lobby;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        [$this->programme, $this->lobby] = $this->publishedProgramme();
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

    /** @return array{0: Programme, 1: string} */
    private function publishedProgramme(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'AS-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
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

    /** @return array{0: User, 1: User} student, guardian */
    private function pooledStudent(): array
    {
        app(ScopeContext::class)->setSystem();
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/my/enrolments', ['programme_id' => $this->programme->id, 'student_id' => $student->id]);
        $req = DB::table('consent_requests')->where('student_id', $student->id)->where('signer_id', $guardian->id)->whereIn('status', ['sent', 'viewed'])->first();
        $this->getJson("/api/consent-requests/{$req->id}/document?language=en")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/scrolled")->assertOk();
        $this->postJson("/api/consent-requests/{$req->id}/sign", ['affirmed' => true, 'method' => 'typed', 'typed_name' => 'G'])->assertStatus(201);
        app(EnrolmentService::class)->evaluateConsentGate($this->programme->id, $student->id, $guardian);
        $this->app['auth']->forgetGuards();

        return [$student, $guardian];
    }

    /** A confirmed team; returns the two [student, guardian] pairs. */
    private function confirmedTeam(): array
    {
        [$s1, $g1] = $this->pooledStudent();
        Sanctum::actingAs($s1);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->lobby, 'name' => 'T'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        [$s2, $g2] = $this->pooledStudent();
        Sanctum::actingAs($s2);
        $this->postJson("/api/teams/{$teamId}/join")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($s1);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $this->app['auth']->forgetGuards();

        return [[$s1, $g1], [$s2, $g2]];
    }

    private function createAssessment(): string
    {
        Sanctum::actingAs($this->ops);
        $id = $this->postJson("/api/admin/programmes/{$this->programme->id}/assessments", ['title' => 'Final'])->assertStatus(201)->json('id');
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function move(string $assessmentId, string $to): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->ops);
        $r = $this->postJson("/api/admin/assessments/{$assessmentId}/transition", ['to' => $to]);
        $this->app['auth']->forgetGuards();

        return $r;
    }

    private function readResult(User $actor, string $assessmentId, int $studentId)
    {
        Sanctum::actingAs($actor);
        $result = $this->getJson("/api/assessments/{$assessmentId}/results/{$studentId}")->assertOk()->json('result');
        $this->app['auth']->forgetGuards();

        return $result;
    }

    public function test_state_machine_advances_and_blocks_illegal_and_is_terminal_at_released(): void
    {
        $id = $this->createAssessment();
        $this->move($id, 'graded')->assertStatus(409); // draft → graded illegal
        foreach (['published', 'open', 'closed', 'graded', 'released'] as $to) {
            $this->move($id, $to)->assertOk();
        }
        $this->assertSame('released', DB::table('assessments')->where('id', $id)->value('status'));
        $this->move($id, 'open')->assertStatus(409); // released is terminal
    }

    public function test_embargo_family_sees_nothing_until_released(): void
    {
        [[$s1, $g1], ] = $this->confirmedTeam();
        $id = $this->createAssessment();
        foreach (['published', 'open', 'closed'] as $to) {
            $this->move($id, $to)->assertOk();
        }
        // grade s1, then move to Graded — NOT released
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/assessments/{$id}/grade", ['student_id' => $s1->id, 'score' => 85])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->move($id, 'graded')->assertOk();

        // EMBARGO: the family sees NOTHING while Graded-but-not-Released…
        $this->assertNull($this->readResult($s1, $id, $s1->id), 'student must not see a Graded-not-Released result');
        $this->assertNull($this->readResult($g1, $id, $s1->id), 'guardian must not see a Graded-not-Released result');
        // …but the academy (grader) sees it in every state
        $this->assertSame(85, (int) data_get($this->readResult($this->ops, $id, $s1->id), 'score'));

        // Release lifts the embargo
        $this->move($id, 'released')->assertOk();
        $this->assertSame(85, (int) data_get($this->readResult($s1, $id, $s1->id), 'score'), 'student sees their own released result');
        $this->assertSame(85, (int) data_get($this->readResult($g1, $id, $s1->id), 'score'), 'guardian sees their student\'s released result');
    }

    public function test_embargo_five_branch_other_family_and_member_see_zero_even_when_released(): void
    {
        [[$s1, ], [$s2, $g2]] = $this->confirmedTeam();
        $id = $this->createAssessment();
        foreach (['published', 'open', 'closed'] as $to) {
            $this->move($id, $to)->assertOk();
        }
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/admin/assessments/{$id}/grade", ['student_id' => $s1->id, 'score' => 90])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->move($id, 'graded')->assertOk();
        $this->move($id, 'released')->assertOk(); // released — now test who can read s1's result

        // (1) s1 sees own · (2) already covered. Here the NEGATIVE branches, even when Released:
        $this->assertNull($this->readResult($s2, $id, $s1->id), 'another student cannot see s1\'s result');
        $this->assertNull($this->readResult($g2, $id, $s1->id), 'another family\'s guardian cannot see s1\'s result');
        $member = User::factory()->create(['role' => 'member']);
        $this->assertNull($this->readResult($member, $id, $s1->id), 'a Member sees no assessment results');
        // academy still sees all
        $this->assertSame(90, (int) data_get($this->readResult($this->ops, $id, $s1->id), 'score'));
    }
}
