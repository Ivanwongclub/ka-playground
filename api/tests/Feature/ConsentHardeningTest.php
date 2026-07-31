<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Reconciliation\Assertions\ConsentCompleteAtConfirmAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/** S06-6 — the requires_all_guardians at-rest hardening of consent_complete_at_confirm, with teeth. */
class ConsentHardeningTest extends TestCase
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

    /** requires_all_guardians = true programme (started). @return array{0: Programme, 1: string} */
    private function publishedProgramme(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $templateId = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$templateId}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$templateId}/versions/{$v}/publish")->assertOk();
        }
        $programme = Programme::query()->create(['code' => 'CH-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
                'eligibility' => ['capacity' => 10],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $templateId, 'requires_all_guardians' => true],
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

    private function pooledStudent(): User
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

        return $student;
    }

    private function confirmedTeam(): array
    {
        $creator = $this->pooledStudent();
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->lobby, 'name' => 'T'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        $m2 = $this->pooledStudent();
        Sanctum::actingAs($m2);
        $this->postJson("/api/teams/{$teamId}/join")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($creator);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $this->app['auth']->forgetGuards();

        return [$creator, $m2];
    }

    public function test_requires_all_hardening_reds_on_an_active_guardian_who_did_not_sign(): void
    {
        // baseline: requires_all 成團 where each member's single (active) guardian signed → GREEN
        [$s1, ] = $this->confirmedTeam();
        $this->assertTrue($this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check()->passed));

        // the immutable confirm time for s1
        [$enrolmentId, $confirmedAt] = $this->sys(fn () => (function () use ($s1) {
            $e = DB::table('enrolments')->where('programme_id', $this->programme->id)->where('student_id', $s1->id)->value('id');
            $t = DB::table('audit_events')->where('entity_type', 'enrolment')->where('entity_id', $e)->where('action', 'enrolment.confirmed')->value('occurred_at');

            return [$e, $t];
        })());
        $g2 = User::factory()->create(['role' => 'guardian']);

        // plant a SECOND guardian who was ACTIVE AS OF confirm (a backdated guardian_link.created
        // audit, one second before the confirm) but never signed → the hardening must RED
        DB::beginTransaction();
        $linkId = (string) Str::uuid7();
        $this->sys(function () use ($linkId, $s1, $g2, $confirmedAt) {
            DB::table('guardian_links')->insert(['id' => $linkId, 'student_id' => $s1->id, 'guardian_id' => $g2->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert([
                'event_id' => (string) Str::uuid7(), 'occurred_at' => Carbon::parse($confirmedAt)->subSecond(),
                'entity_type' => 'guardian_link', 'entity_id' => $linkId, 'action' => 'guardian_link.created',
                'to_state' => 'active', 'actor_role' => 'guardian', 'request_id' => (string) Str::uuid7(),
            ]);
        });
        $red = $this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check());
        $this->assertFalse($red->passed, 'a requires_all confirm with an active guardian who did not sign must red');
        $this->assertStringContainsString('required guardian', $red->details);

        // GREEN branch A: that guardian was REVOKED before confirm → not active-as-of-confirm → excluded
        $this->sys(fn () => DB::table('audit_events')->insert([
            'event_id' => (string) Str::uuid7(), 'occurred_at' => Carbon::parse($confirmedAt)->subSeconds(2),
            'entity_type' => 'guardian_link', 'entity_id' => $linkId, 'action' => 'guardian_link.revoked',
            'to_state' => 'revoked', 'actor_role' => 'academy_admin', 'request_id' => (string) Str::uuid7(),
        ]));
        $this->assertTrue($this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check()->passed), 'a guardian revoked before confirm is not judged active-as-of-confirm');
        DB::rollBack();

        // GREEN branch B: removing the planted guardian entirely restores green
        $this->assertTrue($this->sys(fn () => (new ConsentCompleteAtConfirmAssertion)->check()->passed));
    }
}
