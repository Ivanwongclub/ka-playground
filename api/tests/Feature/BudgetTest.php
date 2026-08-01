<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\School;
use App\Models\TeamBudget;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Reconciliation\Assertions\BudgetApprovedProvenanceAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/**
 * S07 STEP 1 — team-project budgets (record-only). The §P1 state machine, the
 * changes-requested loop, teacher-only approval (reused S05 authority),
 * DB-enforced line immutability once Active (BI-5), and the Plan-gate budget
 * precondition.
 */
class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

    private School $school;

    private Programme $programme;

    private string $lobby;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->bind(VirusScanner::class, EicarOnlyScanner::class);
        $this->ops = User::factory()->create(['role' => 'academy_admin']);
        foreach (['configuration', 'operations'] as $c) {
            DB::table('admin_capabilities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->ops->id, 'capability' => $c, 'granted_by' => $this->ops->id, 'granted_at' => now()]);
        }
        $this->school = $this->sys(fn () => School::create(['name_en' => 'Sch'.Str::random(3), 'name_tc' => '甲', 'name_sc' => '甲']));
        [$this->programme, $this->lobby] = $this->publishedProgramme();
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

    private function publishedProgramme(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $tid = $this->postJson('/api/admin/consent-templates', ['name_en' => 'T', 'name_tc' => '甲', 'name_sc' => '甲'])->json('id');
        foreach (['en', 'zh-TC', 'zh-SC'] as $l) {
            $v = $this->postJson("/api/admin/consent-templates/{$tid}/versions", ['language' => $l, 'body_html' => '<p>x {{signature}}</p>'])->json('version_id');
            $this->postJson("/api/admin/consent-templates/{$tid}/versions/{$v}/publish")->assertOk();
        }
        $p = Programme::query()->create(['code' => 'BG-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
        foreach (['basics', 'eligibility', 'fees', 'consent', 'team_rules', 'role_library', 'tracker', 'learning', 'certification'] as $k) {
            $data = match ($k) {
                'basics' => ['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2026-06-30'],
                'eligibility' => ['capacity' => 5],
                'fees' => ['has_fee_items' => true],
                'consent' => ['template_ref' => $tid],
                'team_rules' => ['formation_deadline_on' => '2026-06-20', 'min_team_size' => 1],
                default => ['x' => 1],
            };
            $this->putJson("/api/admin/programmes/{$p->id}/wizard/{$k}", ['status' => 'complete', 'data' => $data])->assertOk();
        }
        $this->postJson("/api/admin/programmes/{$p->id}/fee-items", ['name_en' => 'Fee', 'name_tc' => '費', 'name_sc' => '费', 'amount_minor' => 100000, 'currency' => 'HKD'])->assertStatus(201);
        $this->postJson("/api/admin/programmes/{$p->id}/publish")->assertOk();
        $lobby = $this->postJson("/api/admin/programmes/{$p->id}/team-categories", ['name_en' => 'Open', 'name_tc' => '開', 'name_sc' => '开', 'assignment_rule' => 'open', 'is_default' => true])->json('id');
        $this->app['auth']->forgetGuards();
        // the programme has begun (past starts_on) so the tracker is unlocked (FR012)
        $this->sys(fn () => DB::table('wizard_sections')->where('programme_id', $p->id)->where('section_key', 'basics')
            ->update(['data' => json_encode(['enrolment_closes_on' => '2026-06-10', 'starts_on' => '2020-01-01'])]));

        return [$p, $lobby];
    }

    private function pooledStudent(): User
    {
        $guardian = User::factory()->create(['role' => 'guardian']);
        $student = User::factory()->create(['role' => 'student']);
        DB::table('guardian_links')->insert(['id' => (string) Str::uuid7(), 'student_id' => $student->id, 'guardian_id' => $guardian->id, 'status' => 'active', 'origin' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
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

    /** @return array{0:string,1:User,2:User} teamId, a member, the linked teacher */
    private function confirmedTeamWithTeacher(): array
    {
        $creator = $this->pooledStudent();
        Sanctum::actingAs($creator);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->lobby, 'name' => 'T'.Str::random(4)])->json('id');
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->postJson("/api/admin/teams/{$teamId}/teacher-link", ['teacher_id' => $teacher->id])->assertOk();
        $this->app['auth']->forgetGuards();

        return [$teamId, $creator, $teacher];
    }

    private function draftWithLine(string $teamId, User $member): string
    {
        Sanctum::actingAs($member);
        $budgetId = $this->postJson("/api/teams/{$teamId}/budget")->assertStatus(201)->json('budget_id');
        $this->postJson("/api/budgets/{$budgetId}/lines", ['category' => 'materials', 'name' => 'Poster', 'planned_amount_minor' => 30000])->assertStatus(201);
        $this->app['auth']->forgetGuards();

        return $budgetId;
    }

    // ── full state machine incl the changes-requested loop ────────────────────

    public function test_budget_state_machine_and_changes_requested_loop(): void
    {
        [$teamId, $member, $teacher] = $this->confirmedTeamWithTeacher();
        $budgetId = $this->draftWithLine($teamId, $member);

        Sanctum::actingAs($member);
        $this->postJson("/api/budgets/{$budgetId}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $this->app['auth']->forgetGuards();

        // teacher requests changes → changes_requested; member revises → draft
        Sanctum::actingAs($teacher);
        $this->postJson("/api/budgets/{$budgetId}/request-changes", ['notes' => 'trim marketing'])->assertOk()->assertJsonPath('status', 'changes_requested');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($member);
        $this->postJson("/api/budgets/{$budgetId}/revise")->assertOk()->assertJsonPath('status', 'draft');
        $this->postJson("/api/budgets/{$budgetId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();

        // teacher approves → active
        Sanctum::actingAs($teacher);
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk()->assertJsonPath('status', 'active');
        $this->app['auth']->forgetGuards();

        // every edge audited, incl both cycles through under_review (no silent bounce)
        $actions = $this->sys(fn () => DB::table('audit_events')->where('entity_type', 'team_budget')->where('entity_id', $budgetId)->pluck('action'));
        foreach (['team_budget.submitted', 'team_budget.under_review', 'team_budget.changes_requested', 'team_budget.draft', 'team_budget.approved', 'team_budget.active'] as $a) {
            $this->assertContains($a, $actions->all(), "missing audited transition {$a}");
        }
        $this->assertTrue($this->sys(fn () => (new BudgetApprovedProvenanceAssertion)->check()->passed));
    }

    // ── teacher-only approval (reused S05 authority) — five-branch ────────────

    public function test_only_the_teams_teacher_can_approve(): void
    {
        [$teamId, $member, $teacher] = $this->confirmedTeamWithTeacher();
        $budgetId = $this->draftWithLine($teamId, $member);
        Sanctum::actingAs($member);
        $this->postJson("/api/budgets/{$budgetId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();

        // a member cannot approve; an unrelated teacher cannot approve
        Sanctum::actingAs($member);
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $stranger = User::factory()->create(['role' => 'teacher']);
        Sanctum::actingAs($stranger);
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertStatus(403);
        $this->app['auth']->forgetGuards();
        // the team's teacher can
        Sanctum::actingAs($teacher);
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk()->assertJsonPath('status', 'active');
    }

    // ── BI-5: budget lines immutable once Active (DB-enforced) ────────────────

    public function test_budget_lines_are_immutable_once_active_at_the_db(): void
    {
        [$teamId, $member, $teacher] = $this->confirmedTeamWithTeacher();
        $budgetId = $this->draftWithLine($teamId, $member);
        Sanctum::actingAs($member);
        $this->postJson("/api/budgets/{$budgetId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk();
        $this->app['auth']->forgetGuards();

        // raw UPDATE/DELETE on a line of an ACTIVE budget → the trigger refuses
        $lineId = $this->sys(fn () => DB::table('budget_lines')->where('budget_id', $budgetId)->value('id'));
        try {
            $this->sys(fn () => DB::transaction(fn () => DB::table('budget_lines')->where('id', $lineId)->update(['planned_amount_minor' => 999999])));
            $this->fail('expected the immutability trigger to refuse an UPDATE on an active budget line');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable once the budget is active', $e->getMessage());
        }
        try {
            $this->sys(fn () => DB::transaction(fn () => DB::table('budget_lines')->where('id', $lineId)->delete()));
            $this->fail('expected the immutability trigger to refuse a DELETE on an active budget line');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable once the budget is active', $e->getMessage());
        }
    }

    // ── the Plan gate is green ONLY when the budget is Active ──────────────────

    public function test_plan_gate_requires_an_active_budget(): void
    {
        [$teamId, $member, $teacher] = $this->confirmedTeamWithTeacher();
        $budgetId = $this->draftWithLine($teamId, $member);

        // before the budget is active, the teacher cannot pass Plan
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->assertStatus(422);
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($member);
        $this->postJson("/api/budgets/{$budgetId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk();
        // now Plan passes
        $this->postJson("/api/teams/{$teamId}/gates/Plan/approve")->assertOk()->assertJsonPath('approver_kind', 'teacher');
    }

    // ── provenance teeth ──────────────────────────────────────────────────────

    public function test_budget_approved_provenance_reds_on_an_active_budget_without_approval(): void
    {
        [$teamId] = $this->confirmedTeamWithTeacher();
        $this->assertTrue($this->sys(fn () => (new BudgetApprovedProvenanceAssertion)->check()->passed), 'green with no budgets');

        // forge an ACTIVE budget with NO approval audit → red
        $this->sys(function () use ($teamId) {
            DB::table('team_budgets')->insert(['id' => (string) Str::uuid7(), 'team_id' => $teamId, 'status' => 'active', 'currency' => 'HKD', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->assertFalse($this->sys(fn () => (new BudgetApprovedProvenanceAssertion)->check()->passed), 'reds on an active budget without an approval audit');
    }
}
