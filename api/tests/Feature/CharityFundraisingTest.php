<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Services\Authz\ScopeContext;
use App\Services\Enrolments\EnrolmentService;
use App\Services\Reconciliation\Assertions\CharityNoDistributionAssertion;
use App\Services\Uploads\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EicarOnlyScanner;
use Tests\TestCase;

/**
 * S07 STEP 3 — sponsorship/charity (OD-4). A charity project can never
 * distribute funds to a team member; the Pitch gate reads the funding target
 * live.
 */
class CharityFundraisingTest extends TestCase
{
    use RefreshDatabase;

    private User $ops;

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
        $p = Programme::query()->create(['code' => 'CH-'.Str::upper(Str::random(5)), 'name_en' => 'P', 'name_tc' => 'P', 'name_sc' => 'P', 'jurisdiction' => 'HK']);
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

    /** @return array{0:string,1:User,2:User,3:User} teamId, memberA, memberB, teacher */
    private function team(): array
    {
        $a = $this->pooledStudent();
        Sanctum::actingAs($a);
        $teamId = $this->postJson('/api/my/teams', ['programme_id' => $this->programme->id, 'category_id' => $this->lobby, 'name' => 'T'.Str::random(4)])->json('id');
        $this->app['auth']->forgetGuards();
        $b = $this->pooledStudent();
        Sanctum::actingAs($b);
        $this->postJson("/api/teams/{$teamId}/join")->assertOk();
        Sanctum::actingAs($a);
        $this->postJson("/api/teams/{$teamId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->postJson("/api/admin/teams/{$teamId}/teacher-link", ['teacher_id' => $teacher->id])->assertOk();
        $this->app['auth']->forgetGuards();

        return [$teamId, $a, $b, $teacher];
    }

    // ── OD-4: a charity project cannot distribute to a member ─────────────────

    public function test_charity_project_refuses_a_member_distribution_but_sponsorship_allows_it(): void
    {
        [$teamId, $a, $b, ] = $this->team();

        // declare CHARITY, then try to record an expense paying a member → refused (OD-4)
        Sanctum::actingAs($a);
        $this->postJson("/api/teams/{$teamId}/fundraising", ['project_type' => 'charity', 'funding_target_minor' => 0])->assertStatus(201);
        $this->postJson("/api/teams/{$teamId}/transactions", ['type' => 'expense', 'amount_minor' => 5000, 'beneficiary_member_id' => $b->id, 'description' => 'payout', 'occurred_on' => '2026-05-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['beneficiary_member_id']);
        // a NON-member expense (no beneficiary) on a charity project is fine
        $this->postJson("/api/teams/{$teamId}/transactions", ['type' => 'expense', 'amount_minor' => 5000, 'description' => 'venue', 'occurred_on' => '2026-05-01'])->assertStatus(201);

        // re-declare SPONSORSHIP → a member beneficiary is now allowed
        $this->postJson("/api/teams/{$teamId}/fundraising", ['project_type' => 'sponsorship', 'funding_target_minor' => 0])->assertStatus(201);
        $this->postJson("/api/teams/{$teamId}/transactions", ['type' => 'expense', 'amount_minor' => 5000, 'beneficiary_member_id' => $b->id, 'description' => 'reimbursement', 'occurred_on' => '2026-05-01'])->assertStatus(201);

        $this->assertTrue($this->sys(fn () => (new CharityNoDistributionAssertion)->check()->passed));
    }

    // ── OD-4 assertion teeth (path-independent) ───────────────────────────────

    public function test_charity_no_distribution_assertion_reds_on_a_forged_row(): void
    {
        [$teamId, $a, $b, ] = $this->team();
        Sanctum::actingAs($a);
        $this->postJson("/api/teams/{$teamId}/fundraising", ['project_type' => 'charity', 'funding_target_minor' => 0])->assertStatus(201);
        $this->app['auth']->forgetGuards();
        $this->assertTrue($this->sys(fn () => (new CharityNoDistributionAssertion)->check()->passed), 'green with no distributions');

        // forge a charity expense with a member beneficiary directly (bypassing the app check) → red
        $this->sys(fn () => DB::table('team_transactions')->insert([
            'id' => (string) Str::uuid7(), 'team_id' => $teamId, 'type' => 'expense', 'amount_minor' => 1000, 'currency' => 'HKD',
            'beneficiary_member_id' => $b->id, 'description' => 'sneaky', 'occurred_on' => '2026-05-01', 'status' => 'draft',
            'recorded_by' => $a->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertFalse($this->sys(fn () => (new CharityNoDistributionAssertion)->check()->passed), 'reds on a charity expense to a member however it arose');
    }

    // ── the Pitch gate reads the funding target live ──────────────────────────

    public function test_pitch_gate_requires_the_declared_funding_target(): void
    {
        [$teamId, $a, $b, $teacher] = $this->team();
        // declare a sponsorship target of 40000
        Sanctum::actingAs($a);
        $this->postJson("/api/teams/{$teamId}/fundraising", ['project_type' => 'sponsorship', 'funding_target_minor' => 40000])->assertStatus(201);
        $this->app['auth']->forgetGuards();

        // before any verified income, Pitch is refused
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teams/{$teamId}/gates/Pitch/approve")->assertStatus(422);
        $this->app['auth']->forgetGuards();

        // record + verify sponsorship income of 40000
        Sanctum::actingAs($a);
        $txnId = $this->postJson("/api/teams/{$teamId}/transactions", ['type' => 'income', 'amount_minor' => 40000, 'description' => 'Sponsor Ltd', 'occurred_on' => '2026-05-01'])->json('transaction_id');
        $this->postJson("/api/transactions/{$txnId}/evidence", ['file' => UploadedFile::fake()->image('agreement.jpg')])->assertOk();
        $this->postJson("/api/transactions/{$txnId}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/transactions/{$txnId}/approve")->assertOk()->assertJsonPath('status', 'recorded');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($b); // a second member verifies (SoD)
        $this->postJson("/api/transactions/{$txnId}/verify")->assertOk()->assertJsonPath('status', 'verified');
        $this->app['auth']->forgetGuards();

        // target reached → Pitch passes
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teams/{$teamId}/gates/Pitch/approve")->assertOk()->assertJsonPath('approver_kind', 'teacher');
    }
}
